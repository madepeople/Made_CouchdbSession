<?php
/**
 * @author jonathan@madepeople.se
 */
class Made_CouchdbSession_Model_Session
    implements Zend_Session_SaveHandler_Interface
{
    protected $_instance;

    public function close()
    {

    }

    public function destroy($id)
    {

    }

    public function gc($maxlifetime)
    {

    }

    public function open($save_path, $name)
    {
        $this->_instance = new Couchbase(COUCHBASE_SERVER, COUCHBASE_USER,
                COUCHBASE_PASS, COUCHBASE_BUCKET);
    }

    public function read($id)
    {

    }

    public function write($id, $data)
    {

    }
}