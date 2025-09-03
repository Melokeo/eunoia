# message assembly flow of a chat (cheatsheet)
user input
    |
JS history
    |
(POST)
    |
(create-context.php)     ----->  last assistant-user turn
create_context()                             |
    |       | <- system-prompt.php        injectSystemMemoryReadOnly                  
    |       | <- get_task_context()          |
    |       | <- Current time                |
    |       | <- get_memories()              |
    |       | <------------------------ [Memory v1] (graph based)
    |       |
    |     system stack
    |       |
    |     (db.php)
    |     pack_messages_for_model()
    |       |
    |     array $messages
    |       +
client delta
(last round tool/user)
    |
$outgoing