<?php
/**
 * CouchDB session read/write implementation. Uses document _ids as session IDs
 *
 * @author jonathan@madepeople.se
 */
class Made_CouchdbSession_Model_Session
    implements Zend_Session_SaveHandler_Interface
{
    protected $_socket;
    protected $_maxLifetime;

    protected $_hostname = '127.0.0.1';
    protected $_port = '5984';
    protected $_username;
    protected $_password;
    protected $_databaseName = 'magento_session';

    const XML_BASE_PATH = 'global/couchdb_session';

    /**
     * Set up possible variable overrides from local XML definitions
     */
    public function __construct()
    {
        $this->_maxLifetime = Mage::getStoreConfig('web/cookie/cookie_lifetime');

        $configKeys = array('hostname', 'port', 'username', 'password',
            'databaseName');

        foreach ($configKeys as $key) {
            $xmlPath = self::XML_BASE_PATH . '/' . $key;
            $value = trim((string)Mage::getConfig()->getNode($xmlPath));
            if (!empty($value)) {
                $member = "_" . $key;
                $this->$member = $value;
            }
        }
    }

    /**
     * Setup save handler
     *
     * @return Made_CouchdbSession_Model_Session
     */
    public function setSaveHandler()
    {
        session_set_save_handler(
            array($this, 'open'),
            array($this, 'close'),
            array($this, 'read'),
            array($this, 'write'),
            array($this, 'destroy'),
            array($this, 'gc')
        );

        return $this;
    }

    /**
     * Make a call to the CouchDB server and return the response
     *
     * @see http://wiki.apache.org/couchdb/Getting_started_with_PHP
     * @param string $url  URL to change
     * @param string $method  HTTP method used in this call
     * @param array|string $data  Array or JSON encoded data to transmit
     * @return array
     * @throws Zend_Session_Exception
     */
    protected function _execute($url, $method = 'GET', $data = null)
    {
        $this->_connect();

        $url = '/' . $this->_databaseName . $url;
        $request = "$method $url HTTP/1.0\r\nHost: {$this->_hostname}\r\n";
        if (!empty($this->_username) || !empty($this->_password)) {
            $authentication = base64_encode($this->_username . ':' . $this->_password);
            $request .= 'Authorization: Basic ' . $authentication . "\r\n";
        }

        if ($data) {
            if (is_array($data)) {
                // Assume the data is json encoded if it doesn't come as an array
                $data = Mage::helper('core')->jsonEncode($data);
            }
            $request .= 'Content-Length: ' . strlen($data) . "\r\n";
            $request .= 'Content-Type: application/json' . "\r\n\r\n";
            $request .= $data . "\r\n";
        } else {
            $request .= "\r\n";
        }

        fwrite($this->_socket, $request);

        $response = '';
        while (!feof($this->_socket)) {
            $response .= fgets($this->_socket);
        }

        $this->_disconnect();

        list ($headers, $body) = explode("\r\n\r\n", $response);
        $result = array(
            'headers' => explode("\n", $headers),
            'body_raw' => $body,
            'body' => Mage::helper('core')->jsonDecode($body)
        );

        if (isset($result['body']['error']) && $result['body']['reason'] === 'no_db_file') {
            // The database doesn't exist, create it
            $response = $this->_execute('', 'PUT');
            return $this->_execute($url, $method, $data);
        }

        return $result;
    }

    /**
     * Delete a session from the storage
     *
     * @param string $id
     * @return boolean
     */
    public function destroy($id)
    {
        $this->_execute('/' . $id, 'DELETE');
        return true;
    }

    /**
     * Garbage collection of old sessions
     *
     * @param type $maxlifetime
     */
    public function gc($maxlifetime)
    {
        $documents = $this->_execute('/_all_docs');
        foreach ($documents as $document) {
            if ($document['session_expiry'] < time()) {
                $this->destroy($document['_id']);
            }
        }
    }

    /**
     * Open a socket to the CouchDB server
     *
     * @param string $save_path
     * @param string $name
     * @return boolean
     */
    public function _connect()
    {
        $this->_socket = @fsockopen($this->_hostname, $this->_port,
                $errorCode = null, $errorString = null);

        if (!$this->_socket) {
            $message = 'Could not open CouchDB connection to ' . $this->_hostname
                    . ':' . $this->_port . ' (' . $errorString . ')';

            throw new Zend_Session_Exception($message, $errorCode);
        }
    }

    /**
     * Close the socket connection to CouchDB
     */
    public function _disconnect()
    {
        fclose($this->_socket);
        $this->_socket = null;
    }

    /**
     * Unused, we open on demand
     *
     * @param type $save_path
     * @param type $name
     * @return boolean
     */
    public function open($save_path, $name)
    {
        return true;
    }

    /**
     * Unused, we close on demand
     *
     * @return boolean
     */
    public function close()
    {
        return true;
    }

    /**
     * Retrieve the associated session document
     *
     * @param string $id
     */
    public function read($id)
    {
        $response = $this->_execute('/' . $id);
        $data = $response['body'];

        if (isset($data['error']) && $data['error'] === 'not_found') {
            // We're writing a new session
            return false;
        }

        return $data['session_data'];
    }

    /**
     * Write update session data. If it fails due to an MVCC conflict, read
     * the session again, and issue the write
     *
     * @param string $id
     * @param array $data
     */
    public function write($id, $data)
    {
        $response = $this->_execute('/' . $id);
        $body = $response['body'];

        if (isset($body['error']) && $body['error'] === 'not_found') {
            // We're writing a new session
            $body = array('_id' => $id);
        }

        $body['session_expiry'] = time()+$this->_maxLifetime;
        $body['session_data'] = $data;

        do {
            $response = $this->_execute('/' . $id, 'PUT', $body);
        } while (isset($response['body']['error']) && $response['body']['error'] === 'conflict');

        return true;
    }
}