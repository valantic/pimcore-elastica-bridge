# Async Settings
## Basic Configuration 
The package is capable of running indexing jobs asynchronously. This is done by using the Symfony Messenger component.
To enable this feature, you need to configure the following settings:

```yaml
# config.yaml
framework:
    messenger:
        transports:
            elastica_bridge_populate: '%env(MESSENGER_TRANSPORT_DSN)%'
```

```dotenv
# .env
MESSENGER_TRANSPORT_DSN='doctrine://default?queue_name=elastica_bridge_populate'
```

This configuration will send all relevant messages to the `elastica_bridge_populate` transport. The `elastica_bridge_populate` transport is
configured to use the `doctrine` transport, which stores the messages in the database. The `queue_name` parameter
specifies the name of the queue where the messages are stored.

## Event Listeners
To take full advantage you will need to configure some event listeners.
See [PopulateListener.php](/docs/example/src/EventListener/PopulateListener.php) and [PopulateService.php](/docs/example/src/Service/PopulateService.php) for a full working example.

The package provides the following events:

| Event Description             | Possible Use Cases                                                                                              | Event Name                   | Event Class               |
|-------------------------------|-----------------------------------------------------------------------------------------------------------------|------------------------------|---------------------------|
| Before the index is populated | <ul><li>Determine the source of the message and possibly clear previous errors</li></ul>                        | `PRE_EXECUTE`                | `PreExecuteEvent`         |
| Before the index is populated | <ul><li>Set expected message count</li></ul>                                                                    | `PRE_PROCESS_MESSAGES_EVENT` | `PreProcessMessagesEvent` |
| Before a document is created  | <ul><li>Stop document creation if execution is locked</li><li>give the remaining messages for logging</li></ul> | `PRE_DOCUMENT_CREATE`        | `PreDocumentCreateEvent`  |
| After a document is created   | <ul><li>Decrement remaining messages</li><li>lock execution if document creation failed</li></ul>               | `POST_DOCUMENT_CREATE`       | `PostDocumentCreateEvent` |
| Before a index is switched    | <ul><li>Skip switch if execution is locked</li><li>update the remaining messages</li></ul>                      | `PRE_SWITCH_INDEX`           | `PreSwitchIndexEvent`     |
| Before a index is switched    | <ul><li>Check if all messages are consumed</li><li>update the remaining messages</li></ul>                      | `WAIT_FOR_COMPLETION_EVENT`  | `WaitForCompletionEvent`  |
| After a index is switched     | <ul><li>Log</li><li>Send Notifications</li></ul>                                                                | `POST_SWITCH_INDEX`          | `PostSwitchIndexEvent`    |



## Workers
Workers are preferably setup using a supervisor configuration. The following is an example configuration for a worker:

### Queue Worker
To process the messages, you need to set up a worker. This can be done by running the following command:

```shell
$ bin/console messenger:consume elastica_bridge_populate
```

### Scheduler Worker
To process the messages in a scheduled manner, you can use the following command:

```shell
$ bin/console messenger:consume scheduler_populate_index
```
