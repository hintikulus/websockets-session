<?php
/**
 * SwitchableSession.php
 *
 * @copyright      More in license.md
 * @license        http://www.ipublikuj.eu
 * @author         Adam Kadlec http://www.ipublikuj.eu
 * @package        iPublikuj:WebSocketSession!
 * @subpackage     Session
 * @since          1.0.0
 *
 * @date           21.02.17
 */

declare(strict_types = 1);

namespace IPub\WebSocketsSession\Session;

use Nette\Diagnostics\Debugger;
use Nette\Http;

use IPub;
use IPub\WebSocketsSession\Exceptions;
use IPub\WebSocketsSession\Serializers;

use IPub\WebSockets\Entities as WebSocketsEntities;
use IPub\WebSockets\Http as WebSocketsHttp;

/**
 * WebSocket session switcher
 *
 * @package        iPublikuj:WebSocketSession!
 * @subpackage     Session
 *
 * @author         Adam Kadlec <adam.kadlec@ipublikuj.eu>
 */
final class SwitchableSession extends Http\Session
{
	/**
	 * @var Http\Session
	 */
	private $systemSession;

	/**
	 * @var WebSocketsEntities\Clients\IClient|NULL
	 */
	private $client;

	/**
	 * Has been session started?
	 *
	 * @var bool
	 */
	private $started = FALSE;

	/**
	 * @var array
	 */
	private $data = [];

	/**
	 * @var array
	 */
	private $sections = [];

	/**
	 * @var bool
	 */
	private $attached = FALSE;

	/**
	 * @var \SessionHandlerInterface|NULL
	 */
	private $handler;

	/**
	 * @var NullHandler
	 */
	private $nullHandler;

	/**
	 * @var Serializers\ISessionSerializer
	 */
	private $serializer;

	/**
	 * @param Http\Session $session
	 * @param Serializers\ISessionSerializer $serializer
	 * @param \SessionHandlerInterface|NULL $handler
	 */
	public function __construct(
		Http\Session $session,
		Serializers\ISessionSerializer $serializer,
		\SessionHandlerInterface $handler = NULL
	) {
		$this->systemSession = $session;
		$this->handler = $handler;
		$this->nullHandler = new NullHandler;
		$this->serializer = $serializer;
	}

	/**
	 * @param WebSocketsEntities\Clients\IClient $client
	 * @param WebSocketsHttp\IRequest $httpRequest
	 *
	 * @return void
	 *
	 * @throws Exceptions\InvalidArgumentException
	 * @throws Exceptions\LogicException
	 */
	public function attach(WebSocketsEntities\Clients\IClient $client, WebSocketsHttp\IRequest $httpRequest)
	{
		if ($this->systemSession->isStarted()) {
			throw new Exceptions\LogicException('Session is already started, please close it first and then you can disabled it.');
		}

		$client->addParameter('sessionId', $httpRequest->getCookie($this->systemSession->getName()));

		$this->attached = TRUE;
		$this->started = FALSE;

		$this->client = $client;
	}

	/**
	 * @return void
	 */
	public function detach()
	{
		if ($this->attached) {
			$this->close();

			$this->attached = FALSE;

			$this->client = NULL;
		}
	}

	/**
	 * @return bool
	 */
	public function isAttached() : bool
	{
		return $this->attached;
	}

	/**
	 * {@inheritdoc}
	 */
	public function start()
	{
		if (!$this->attached) {
			$this->systemSession->start();

			return;
		}

		if ($this->started) {
			return;
		}

		$this->started = TRUE;

		if (($id = $this->client->getParameter('sessionId')) === NULL) {
			$handler = $this->nullHandler;
			$id = '';

		} else {
			$handler = $this->handler;
		}

		$handler->open(session_save_path(), $this->systemSession->getName());

		$rawData = $handler->read($id);

		$data = $this->serializer->unserialize($rawData);

		/* structure:
			__NF: Data, Meta, Time
				DATA: section->variable = data
				META: section->variable = Timestamp
		*/
		$nf = &$data['__NF'];

		if (!is_array($nf)) {
			$nf = [];
		}

		// regenerate empty session
		if (empty($nf['Time'])) {
			$nf['Time'] = time();
		}

		// process meta metadata
		if (isset($nf['META'])) {
			$now = time();
			// expire section variables
			foreach ($nf['META'] as $section => $metadata) {
				if (is_array($metadata)) {
					foreach ($metadata as $variable => $value) {
						if (!empty($value['T']) && $now > $value['T']) {
							if ($variable === '') { // expire whole section
								unset($nf['META'][$section], $nf['DATA'][$section]);
								continue 2;
							}
							unset($nf['META'][$section][$variable], $nf['DATA'][$section][$variable]);
						}
					}
				}
			}
		}

		$this->data[$this->getConnectionId()] = $data;
	}

	/**
	 * {@inheritdoc}
	 */
	public function isStarted()
	{
		if (!$this->attached) {
			return $this->systemSession->isStarted();
		}

		return $this->started;
	}

	/**
	 * {@inheritdoc}
	 */
	public function close()
	{
		if (!$this->attached) {
			$this->systemSession->close();

		} else {
			$this->started = FALSE;

			$this->handler->close();
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function destroy()
	{
		if (!$this->attached) {
			$this->systemSession->destroy();

		} else {
			if (!$this->started) {
				throw new Exceptions\InvalidStateException('Session is not started.');
			}

			$this->started = FALSE;

			$this->data = [];
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function exists()
	{
		if (!$this->attached) {
			return $this->systemSession->exists();
		}

		return $this->started;
	}

	/**
	 * {@inheritdoc}
	 */
	public function regenerateId()
	{
		if (!$this->attached) {
			$this->systemSession->regenerateId();
		}

		// For WS session nothing to do
	}

	/**
	 * {@inheritdoc}
	 */
	public function getId()
	{
		if (!$this->attached) {
			return $this->systemSession->getId();
		}

		return $this->client->getParameter('sessionId');
	}

	/**
	 * {@inheritdoc}
	 */
	public function getSection($section, $class = 'Nette\Http\SessionSection')
	{
		if (!$this->attached) {
			return $this->systemSession->getSection($section, $class);
		}

		return new SessionSection($this, $section);
	}

	/**
	 * {@inheritdoc}
	 */
	public function hasSection($section)
	{
		if (!$this->attached) {
			return $this->systemSession->hasSection($section);
		}

		return isset($this->sections[$this->getConnectionId()][$section]);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getIterator() : \ArrayIterator
	{
		if (!$this->attached) {
			return $this->systemSession->getIterator();
		}

		return new \ArrayIterator(array_keys($this->sections[$this->getConnectionId()]));
	}

	/**
	 * {@inheritdoc}
	 */
	public function clean()
	{
		if (!$this->attached) {
			$this->systemSession->clean();

		} else {
			if (!$this->started || empty($this->data[$this->getConnectionId()])) {
				return;
			}

			$nf = &$this->data[$this->getConnectionId()]['__NF'];
			if (isset($nf['META']) && is_array($nf['META'])) {
				foreach ($nf['META'] as $name => $foo) {
					if (empty($nf['META'][$name])) {
						unset($nf['META'][$name]);
					}
				}
			}

			if (empty($nf['META'])) {
				unset($nf['META']);
			}

			if (empty($nf['DATA'])) {
				unset($nf['DATA']);
			}
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function setName($name)
	{
		return $this->systemSession->setName($name);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getName()
	{
		return $this->systemSession->getName();
	}

	/**
	 * {@inheritdoc}
	 */
	public function setOptions(array $options)
	{
		return $this->systemSession->setOptions($options);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getOptions()
	{
		return $this->systemSession->getOptions();
	}

	/**
	 * {@inheritdoc}
	 */
	public function setExpiration($time)
	{
		return $this->systemSession->setExpiration($time);
	}

	/**
	 * {@inheritdoc}
	 */
	public function setCookieParameters($path, $domain = NULL, $secure = NULL)
	{
		return $this->systemSession->setCookieParameters($path, $domain, $secure);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getCookieParameters()
	{
		return $this->systemSession->getCookieParameters();
	}

	/**
	 * {@inheritdoc}
	 */
	public function setSavePath($path)
	{
		return $this->systemSession->setSavePath($path);
	}

	/**
	 * {@inheritdoc}
	 */
	public function setHandler(\SessionHandlerInterface $handler)
	{
		return $this->systemSession->setHandler($handler);
	}

	/**
	 * @param string $section
	 *
	 * @return array
	 */
	public function getData(string $section) : array
	{
		if (!$this->attached) {
			return $_SESSION['DATA'][$section];
		}

		return isset($this->data[$this->getConnectionId()]['__NF']['DATA'][$section]) ? $this->data[$this->getConnectionId()]['__NF']['DATA'][$section] : [];
	}

	/**
	 * @return int
	 *
	 * @throws Exceptions\InvalidStateException
	 */
	private function getConnectionId() : int
	{
		return $this->client->getId();
	}
}
