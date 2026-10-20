<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Live Session Test Harness</title>
    <!-- LiveKit JS Client -->
    <script src="https://cdn.jsdelivr.net/npm/livekit-client/dist/livekit-client.umd.min.js"></script>
    <!-- Axios for API calls -->
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <style>
        body { font-family: sans-serif; padding: 20px; }
        .panel { border: 1px solid #ccc; padding: 15px; margin-bottom: 20px; border-radius: 5px; }
        #video-container video, #video-container audio { max-width: 400px; background: #222; margin: 5px; }
        .hidden { display: none; }
    </style>
</head>
<body>

    <h1>Live Session Test Harness</h1>
    
    <div class="panel" id="auth-panel">
        <h3>1. Login</h3>
        <input type="email" id="email" placeholder="Email" value="admin@test.com">
        <input type="password" id="password" placeholder="Password" value="password">
        <button onclick="login()">Login</button>
        <p id="auth-status"></p>
    </div>

    <div class="panel hidden" id="admin-panel">
        <h3>Admin Controls</h3>
        <button onclick="createSession()">Create Session</button>
        <button onclick="startSession()">Start Session</button>
        <br><br>
        <input type="text" id="session-id" placeholder="Session ID">
        <button onclick="joinSession()">Join Session</button>
    </div>

    <div class="panel hidden" id="patient-panel">
        <h3>Patient Controls</h3>
        <input type="text" id="join-session-id" placeholder="Session ID to Join">
        <button onclick="joinSession()">Join Session</button>
        <br><br>
        <button onclick="raiseHand()">Raise Hand</button>
    </div>

    <div class="panel hidden" id="room-panel">
        <h3>Live Room</h3>
        <p>Connected as: <span id="participant-identity"></span></p>
        <button onclick="leaveRoom()">Leave Room</button>
        
        <div id="video-container"></div>
    </div>

    <script>
        let apiToken = '';
        let currentUser = null;
        let currentSessionId = null;
        let room = null;

        const api = axios.create({ baseURL: '/api/v1' });
        api.interceptors.request.use(config => {
            if (apiToken) config.headers.Authorization = `Bearer ${apiToken}`;
            return config;
        });

        async function login() {
            try {
                const res = await api.post('/auth/login', {
                    email: document.getElementById('email').value,
                    password: document.getElementById('password').value
                });
                apiToken = res.data.token || res.data.data.token;
                currentUser = res.data.user || res.data.data.user;
                
                document.getElementById('auth-status').innerText = `Logged in as ${currentUser.role}`;
                document.getElementById('auth-panel').classList.add('hidden');
                
                if (currentUser.role === 'admin') {
                    document.getElementById('admin-panel').classList.remove('hidden');
                } else {
                    document.getElementById('patient-panel').classList.remove('hidden');
                }
            } catch (err) {
                alert('Login failed: ' + (err.response?.data?.message || err.message));
            }
        }

        async function createSession() {
            try {
                const res = await api.post('/live-sessions', { title: 'Test Session via API' });
                currentSessionId = res.data.data.id;
                document.getElementById('session-id').value = currentSessionId;
                alert('Session created! ID: ' + currentSessionId);
            } catch (err) {
                alert('Create failed');
            }
        }

        async function startSession() {
            try {
                const id = document.getElementById('session-id').value;
                await api.post(`/live-sessions/${id}/start`);
                alert('Session started!');
            } catch (err) {
                alert('Start failed');
            }
        }

        async function joinSession() {
            const id = currentUser.role === 'admin' ? document.getElementById('session-id').value : document.getElementById('join-session-id').value;
            currentSessionId = id;
            try {
                const res = await api.post(`/live-sessions/${id}/join-token`);
                const { token, livekit_url, participant } = res.data.data;
                document.getElementById('participant-identity').innerText = participant.anonymous_label;
                
                await connectLiveKit(livekit_url, token);
                
                document.getElementById('admin-panel').classList.add('hidden');
                document.getElementById('patient-panel').classList.add('hidden');
                document.getElementById('room-panel').classList.remove('hidden');
            } catch (err) {
                alert('Join failed: ' + (err.response?.data?.message || err.message));
            }
        }

        async function connectLiveKit(url, token) {
            room = new LivekitClient.Room({
                adaptiveStream: true,
                dynacast: true,
            });

            room.on(LivekitClient.RoomEvent.TrackSubscribed, (track, publication, participant) => {
                const container = document.getElementById('video-container');
                const element = track.attach();
                container.appendChild(element);
            });

            room.on(LivekitClient.RoomEvent.TrackUnsubscribed, (track, publication, participant) => {
                track.detach().forEach(el => el.remove());
            });

            await room.connect(url, token);

            // If we have permissions, publish camera and mic (Admin)
            if (room.localParticipant.permissions.canPublish) {
                await room.localParticipant.enableCameraAndMicrophone();
            }
        }

        async function raiseHand() {
            try {
                await api.post(`/live-sessions/${currentSessionId}/raise-hand`);
                alert('Hand raised! Wait for admin approval.');
            } catch (err) {
                alert('Failed to raise hand');
            }
        }

        function leaveRoom() {
            if (room) {
                room.disconnect();
                room = null;
            }
            document.getElementById('video-container').innerHTML = '';
            document.getElementById('room-panel').classList.add('hidden');
            
            if (currentUser.role === 'admin') {
                document.getElementById('admin-panel').classList.remove('hidden');
            } else {
                document.getElementById('patient-panel').classList.remove('hidden');
            }
        }
    </script>
</body>
</html>
