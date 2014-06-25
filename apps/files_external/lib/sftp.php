<?php
/**
 * Copyright (c) 2012 Henrik Kjölhede <hkjolhede@gmail.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */
namespace OC\Files\Storage;

//https://github.com/phpseclib/phpseclib/issues/391
require_once dirname(__FILE__) . '/../../../3rdparty/phpseclib/phpseclib/phpseclib/Net/SFTP/Stream.php';

class SFTP extends \OC\Files\Storage\Common {
	private $host;
	private $user;
	private $password;
	private $root;

	private $client;

	public function __construct($params) {
		$this->host = $params['host'];
		$proto = strpos($this->host, '://');
		if ($proto != false) {
			$this->host = substr($this->host, $proto+3);
		}
		$this->user = $params['user'];
		$this->password = $params['password'];

		if (!empty($params['usesshkey'])) {
			if (($keyfile = \OC_Config::getValue('sftpsshkey'))) {
				if (!is_file($keyfile) || !is_readable($keyfile)) {
					throw new \Exception('SSH key "' . $keyfile . '" is not existent or readable');
				}
				$key = new \Crypt_RSA();
				if ($this->password) {
					$key->setPassword($this->password);
				}
				$key->loadKey(file_get_contents($keyfile));
				$this->password = $key;
			}
		}

		$this->root = isset($params['root']) ? $this->cleanPath($params['root']) : '/';

		if ($this->root[0] != '/') {
			 $this->root = '/' . $this->root;
		}

		if (substr($this->root, -1, 1) != '/') {
			$this->root .= '/';
		}

		$hostKeys = $this->readHostKeys();

		$this->client = new \Net_SFTP($this->host);
		if (!$this->client->login($this->user, $this->password)) {
			throw new \Exception('Login failed: ' . $this->client->getLastError());
		}

		$currentHostKey = $this->client->getServerPublicHostKey();

		if (array_key_exists($this->host, $hostKeys)) {
			if ($hostKeys[$this->host] != $currentHostKey) {
				throw new \Exception('Host public key does not match known key');
			}
		} else {
			$hostKeys[$this->host] = $currentHostKey;
			$this->writeHostKeys($hostKeys);
		}
	}

	public function test() {
		if (
			!isset($this->host)
			|| !isset($this->user)
			|| !isset($this->password)
		) {
			return false;
		}
		return $this->client->nlist() !== false;
	}

	public function getId(){
		return 'sftp::' . $this->user . '@' . $this->host . '/' . $this->root;
	}

	/**
	 * @param string $path
	 */
	private function absPath($path) {
		return $this->root . $this->cleanPath($path);
	}

	private function hostKeysPath() {
		try {
			$storage_view = \OCP\Files::getStorage('files_external');
			if ($storage_view) {
				return \OCP\Config::getSystemValue('datadirectory') .
					$storage_view->getAbsolutePath('') .
					'ssh_hostKeys';
			}
		} catch (\Exception $e) {
		}
		return false;
	}

	private function writeHostKeys($keys) {
		try {
			$keyPath = $this->hostKeysPath();
			if ($keyPath && file_exists($keyPath)) {
				$fp = fopen($keyPath, 'w');
				foreach ($keys as $host => $key) {
					fwrite($fp, $host . '::' . $key . "\n");
				}
				fclose($fp);
				return true;
			}
		} catch (\Exception $e) {
		}
		return false;
	}

	private function readHostKeys() {
		try {
			$keyPath = $this->hostKeysPath();
			if (file_exists($keyPath)) {
				$hosts = array();
				$keys = array();
				$lines = file($keyPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
				if ($lines) {
					foreach ($lines as $line) {
						$hostKeyArray = explode("::", $line, 2);
						if (count($hostKeyArray) == 2) {
							$hosts[] = $hostKeyArray[0];
							$keys[] = $hostKeyArray[1];
						}
					}
					return array_combine($hosts, $keys);
				}
			}
		} catch (\Exception $e) {
		}
		return array();
	}

	public function mkdir($path) {
		try {
			return $this->client->mkdir($this->absPath($path));
		} catch (\Exception $e) {
			return false;
		}
	}

	public function rmdir($path) {
		try {
			return $this->client->delete($this->absPath($path), true);
		} catch (\Exception $e) {
			return false;
		}
	}

	public function opendir($path) {
		try {
			$list = $this->client->nlist($this->absPath($path));

			$id = md5('sftp:' . $path);
			$dirStream = array();
			foreach($list as $file) {
				if ($file != '.' && $file != '..') {
					$dirStream[] = $file;
				}
			}
			\OC\Files\Stream\Dir::register($id, $dirStream);
			return opendir('fakedir://' . $id);
		} catch(\Exception $e) {
			return false;
		}
	}

	public function filetype($path) {
		try {
			$stat = $this->client->stat($this->absPath($path));
			if ($stat['type'] == NET_SFTP_TYPE_REGULAR) {
				return 'file';
			}

			if ($stat['type'] == NET_SFTP_TYPE_DIRECTORY) {
				return 'dir';
			}
		} catch (\Exception $e) {

		}
		return false;
	}

	public function file_exists($path) {
		try {
			return $this->client->stat($this->absPath($path)) !== false;
		} catch (\Exception $e) {
			return false;
		}
	}

	public function unlink($path) {
		try {
			return $this->client->delete($this->absPath($path), true);
		} catch (\Exception $e) {
			return false;
		}
	}

	public function fopen($path, $mode) {
		try {
			switch($mode) {
				case 'r':
				case 'rb':
					if ( !$this->file_exists($path)) {
						return false;
					}
				case 'w':
				case 'wb':
				case 'a':
				case 'ab':
				case 'r+':
				case 'w+':
				case 'wb+':
				case 'a+':
				case 'x':
				case 'x+':
				case 'c':
				case 'c+':
					// FIXME: make client login lazy to prevent it when using fopen()
					$opts = is_object($this->password) ? array('sftp' => array('privkey' => $this->password)) : array();
					$context = stream_context_create($opts);
					return fopen($this->constructUrl($path), $mode, null, $context);
			}
		} catch (\Exception $e) {
		}
		return false;
	}

	public function touch($path, $mtime=null) {
		try {
			if (!is_null($mtime)) {
				return false;
			}
			if (!$this->file_exists($path)) {
				$this->client->put($this->absPath($path), '');
			} else {
				return false;
			}
		} catch (\Exception $e) {
			return false;
		}
		return true;
	}

	public function getFile($path, $target) {
		$this->client->get($path, $target);
	}

	public function uploadFile($path, $target) {
		$this->client->put($target, $path, NET_SFTP_LOCAL_FILE);
	}

	public function rename($source, $target) {
		try {
			if (!$this->is_dir($target) && $this->file_exists($target)) {
				$this->unlink($target);
			}
			return $this->client->rename(
				$this->absPath($source),
				$this->absPath($target)
			);
		} catch (\Exception $e) {
			return false;
		}
	}

	public function stat($path) {
		try {
			$stat = $this->client->stat($this->absPath($path));

			$mtime = $stat ? $stat['mtime'] : -1;
			$size = $stat ? $stat['size'] : 0;

			return array('mtime' => $mtime, 'size' => $size, 'ctime' => -1);
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * @param string $path
	 * @return string
	 */
	public function constructUrl($path) {
		$url = 'sftp://'.$this->user;
		if (!is_object($this->password)) {
			$url .= ':'.$this->password.'';
		}
		$url .= '@'.$this->host.$this->root.$path;
		return $url;
	}
}
