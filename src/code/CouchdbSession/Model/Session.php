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
     * Create the database and set up the view needed for garbage collection
     * as well as the show function used in conjunction with Varnish
     */
    protected function _initialize()
    {
        // @TODO: Error handling, what is even error handling in this state?
        $this->_execute('', 'PUT');
        $designDocument = Mage::helper('core')->jsonEncode(array(
            '_id' => '_design/misc',
            'shows' => array('is_session_valid' => "
                    function(doc, req) {
                        if (!doc) {
                            return false;
                        }
                        var now = Math.round(new Date().getTime() / 1000);
                        var expiry = doc['session_expiry'];
                        return {
                                'code':200,
                                'headers':{ 'content-type': 'text/plain' },
                                'body': ''+(expiry > now)
                            };
                    }"
            ),
            'views' => array(
                'gc' => array('map' => "
                    function(doc) {
                        if ('session_expiry' in doc) {
                            emit(doc.session_expiry, doc._rev);
                        }
                    }"
                )
            )
        ));
        $this->_execute('/_design/misc', 'PUT', $designDocument);
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

        register_shutdown_function('session_write_close');

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

        list ($headers, $body) = explode("\r\n\r\n", $response, 2);
        $result = array(
            'headers' => explode("\n", $headers),
            'body_raw' => $body,
            'body' => Mage::helper('core')->jsonDecode($body)
        );

        if (isset($result['body']['error']) && $result['body']['reason'] === 'no_db_file') {
            // The database doesn't exist, create it
            $this->_initialize();
            return $this->_execute($url, $method, $data);
        }

        return $result;
    }

    /**
     * Delete a session from the storage. If PHP calls this we need to fetch
     * the latest revision manually.
     *
     * @param string $id
     * @param string|null $revision
     * @return boolean
     */
    public function destroy($id, $revision = null)
    {
        if (empty($id)) {
            return false;
        }
        if (empty($revision)) {
            $response = $this->_execute('/' . $id);
            if (isset($response['body']['error'])) {
                return false;
            }
            $revision = $response['body']['_rev'];
        }
        $url = '/' . $id . '?rev=' . $revision;
        $this->_execute($url , 'DELETE');
        return true;
    }

    /**
     * Garbage collection of old sessions
     *
     * @param type $maxlifetime
     */
    public function gc($maxlifetime = null)
    {
        // Remove the sessions that expired at least a second ago
        $endkey = time()-1;
        $documents = $this->_execute('/_design/misc/_view/gc?endkey=' . $endkey);
        if ($documents['body']['total_rows']) {
            foreach ($documents['body']['rows'] as $document) {
                if ($document['session_expiry'] < time()) {
                    $this->destroy($document['id'], $document['value']);
                }
            }
        }

        $toPurge = array();
        $deletedDocuments = $this->_execute('/_changes');
        foreach ($deletedDocuments['body']['results'] as $document) {
            if ($document['deleted']) {
                $changes = array();
                foreach ($document['changes'] as $change) {
                    $changes[] = $change['rev'];
                }
                if (empty($toPurge[$document['id']])) {
                    $toPurge[$document['id']] = array();
                }
                $toPurge[$document['id']] = array_merge(
                        $toPurge[$document['id']],
                        $changes);
            }
        }

        if (!empty($toPurge)) {
            $result = $this->_execute('/_purge', 'POST', $toPurge);
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
            return "";
        }

        return Mage::helper('core')->jsonDecode($data['session_data']);
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
        $body['session_data'] = Mage::helper('core')->jsonEncode($data);

        while (true) {
            $response = $this->_execute('/' . $id, 'PUT', $body);
            if (isset($response['body']['error']) && $response['body']['error'] === 'conflict') {
                $response = $this->_execute('/' . $id);
                $body = $response['body'];
                if (isset($body['error'])) {
                    return false;
                }
                $body['session_data'] = Mage::helper('core')->jsonEncode($data);
            } else {
                break;
            }
        }

        return true;
    }
}