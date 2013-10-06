Magento CouchDB Session Handler
==
This module adds the possibility to use a CouchDB server for storing Magento sessions.

It has the following benefits:

* MVCC allows for consistency without locking
* Network access with replication enables failover and makes scaling effortless
* Persistence
* [show and list functions](http://wiki.apache.org/couchdb/Formatting_with_Show_and_List) exposes and easy to use external interface for session validation, useful for single sign-on and paywall implementations

Configuration
--
* Install CouchDB
* Install this module in your Magento store
* Make sure that your local.xml is set up to use "db" as session_save

```xml
<session_save><![CDATA[db]]></session_save>
```

* Flush the Magento config cache
	
If CouchDB listens on IP 127.0.0.1 and the default port without a username or password, this should be enough. The reason we use "db" is that there is no other way to add a custom session management class than to override the MySQL one at the moment.

If your setup varies from this, the following options are available:

```xml
<session_save><![CDATA[db]]></session_save>
<couchdb_session>
	<!-- Defaults to "127.0.0.1" -->
    <hostname></hostname>
	<!-- Defaults to "5984" -->
    <port></port>
	<!-- Empty default -->
    <username></username>
	<!-- Empty default -->
    <password></password>
	<!-- Defaults to "magento_session", change this if you have one CouchDB for multiple Magento stores -->
    <databaseName></databaseName>
</couchdb_session>
```

Notes
--
The module doesn't include any means of migrating an existing session storage into CouchDB, meaning that once enabled, every user will need to get new sessions.

License
--
This project is licensed under the 4-clause BSD License, see [LICENSE](https://github.com/madepeople/Made_CouchdbSession/blob/master/LICENSE)
