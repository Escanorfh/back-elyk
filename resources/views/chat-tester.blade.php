<!DOCTYPE html>
<html>
<head>
    <title>Realtime Chat Tester</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: sans-serif; padding: 20px; }
        .box { border: 1px solid #ccc; padding: 10px; margin-bottom: 20px; height: 300px; overflow-y: scroll; }
        .log-entry { margin: 5px 0; padding: 5px; background: #eee; }
    </style>
</head>
<body>
    <h1>Realtime Chat & Alerts Tester</h1>
    
    <div>
        <label>Token:</label>
        <input type="text" id="token" style="width:300px;" placeholder="Bearer Token">
        <button onclick="connectEcho()">Connect</button>
    </div>
    
    <div style="display:flex; gap:20px; margin-top:20px;">
        <div style="flex:1;">
            <h3>Private Chat Channel (Thread ID)</h3>
            <input type="text" id="thread_id" placeholder="Thread ID">
            <button onclick="subscribeChat()">Subscribe Chat</button>
            <div class="box" id="chat-logs"></div>
        </div>

        <div style="flex:1;">
            <h3>Admin Alerts Channel</h3>
            <button onclick="subscribeAdmin()">Subscribe Admins</button>
            <div class="box" id="admin-logs"></div>
        </div>
    </div>

    <script>
        function log(boxId, msg) {
            const b = document.getElementById(boxId);
            b.innerHTML += '<div class="log-entry">' + new Date().toLocaleTimeString() + ': ' + msg + '</div>';
            b.scrollTop = b.scrollHeight;
        }

        function connectEcho() {
            const token = document.getElementById('token').value;
            if (!token) return alert('Need token');

            window.axios.defaults.headers.common['Authorization'] = 'Bearer ' + token;
            
            // Assuming Echo is initialized in app.js
            log('chat-logs', 'Echo configured with token.');
            log('admin-logs', 'Echo configured with token.');
        }

        function subscribeChat() {
            const tid = document.getElementById('thread_id').value;
            if(!tid) return alert('Need thread ID');
            
            window.Echo.private('chat.thread.' + tid)
                .listen('ChatMessageSent', (e) => {
                    log('chat-logs', 'New Message: ' + JSON.stringify(e));
                });
            log('chat-logs', 'Subscribed to chat.thread.' + tid);
        }

        function subscribeAdmin() {
            window.Echo.private('admins')
                .listen('SevereResultAlerted', (e) => {
                    log('admin-logs', 'ALERT: ' + JSON.stringify(e));
                });
            log('admin-logs', 'Subscribed to admins channel.');
        }
    </script>
</body>
</html>
