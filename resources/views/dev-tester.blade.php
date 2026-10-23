<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إليك - Developer API Tester</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { padding-top: 20px; background: #f8f9fa; }
        .sidebar { height: calc(100vh - 40px); overflow-y: auto; }
        .main-panel { height: calc(100vh - 40px); overflow-y: auto; }
        pre { background: #2b2b2b; color: #a9b7c6; padding: 15px; border-radius: 5px; }
        .endpoint-item { cursor: pointer; }
        .endpoint-item:hover { background: #e9ecef; }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 sidebar bg-white p-3 border rounded shadow-sm">
            <h4 class="mb-3">API Endpoints</h4>
            
            <div class="mb-4">
                <h6>Auth</h6>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item endpoint-item" onclick="selectEndpoint('POST', '/api/v1/auth/login', '{\n  &quot;email&quot;: &quot;patient@test.com&quot;,\n  &quot;password&quot;: &quot;password&quot;\n}')">Login</li>
                    <li class="list-group-item endpoint-item" onclick="selectEndpoint('POST', '/api/v1/auth/register', '{\n  &quot;name&quot;: &quot;New Patient&quot;,\n  &quot;email&quot;: &quot;new@test.com&quot;,\n  &quot;password&quot;: &quot;password&quot;\n}')">Register</li>
                    <li class="list-group-item endpoint-item" onclick="selectEndpoint('POST', '/api/v1/auth/logout', '{}')">Logout</li>
                    <li class="list-group-item endpoint-item" onclick="selectEndpoint('GET', '/api/v1/user', '')">Me (User details)</li>
                </ul>
            </div>

            <div class="mb-4">
                <h6>Admin: Treatment Paths</h6>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item endpoint-item" onclick="selectEndpoint('POST', '/api/v1/disorders/1/media-items', '')">Upload Media (Use Postman for files)</li>
                    <li class="list-group-item endpoint-item" onclick="selectEndpoint('GET', '/api/v1/disorders/1/media-items', '')">List Media</li>
                    <li class="list-group-item endpoint-item" onclick="selectEndpoint('GET', '/api/v1/patients/2/paths', '')">List Patient Paths (Patient 2)</li>
                    <li class="list-group-item endpoint-item" onclick="selectEndpoint('POST', '/api/v1/patients/2/paths/1/steps', '{\n  &quot;order&quot;: 1,\n  &quot;audio_media_id&quot;: 1\n}')">Add Path Step</li>
                    <li class="list-group-item endpoint-item" onclick="selectEndpoint('POST', '/api/v1/patient-paths/1/release-next', '{}')">Release Next Step (Path 1)</li>
                    <li class="list-group-item endpoint-item" onclick="selectEndpoint('POST', '/api/v1/assignments/1/feedback', '{\n  &quot;note_text&quot;: &quot;Great job!&quot;,\n  &quot;is_visible_to_patient&quot;: true\n}')">Add Feedback (Assignment 1)</li>
                </ul>
            </div>

            <div class="mb-4">
                <h6>Patient: Treatment Paths</h6>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item endpoint-item" onclick="selectEndpoint('GET', '/api/v1/me/paths', '')">My Paths</li>
                    <li class="list-group-item endpoint-item" onclick="selectEndpoint('GET', '/api/v1/me/paths/1/current-step', '')">My Current Step (Path 1)</li>
                    <li class="list-group-item endpoint-item" onclick="selectEndpoint('POST', '/api/v1/path-steps/1/assignment', '{\n  &quot;text_response&quot;: &quot;This is my answer&quot;\n}')">Submit Assignment</li>
                </ul>
            </div>

            <div class="mb-4">
                <h6>Admin: Builder</h6>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item endpoint-item" onclick="selectEndpoint('GET', '/api/v1/disorders', '')">List Disorders</li>
                    <li class="list-group-item endpoint-item" onclick="selectEndpoint('POST', '/api/v1/disorders', '{\n  &quot;name&quot;: &quot;OCD&quot;,\n  &quot;name_ar&quot;: &quot;الوسواس القهري&quot;\n}')">Create Disorder</li>
                    <li class="list-group-item endpoint-item" onclick="selectEndpoint('GET', '/api/v1/exams', '')">List Exams</li>
                    <li class="list-group-item endpoint-item" onclick="selectEndpoint('GET', '/api/v1/exams/1/full', '')">Get Full Exam (ID 1)</li>
                </ul>
            </div>

            <div class="mb-4">
                <h6>Patient: Flow</h6>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item endpoint-item" onclick="selectEndpoint('GET', '/api/v1/patient/exams', '')">List Active Exams</li>
                    <li class="list-group-item endpoint-item" onclick="selectEndpoint('GET', '/api/v1/patient/exams/1/take', '')">Load Exam to Take (ID 1)</li>
                    <li class="list-group-item endpoint-item" onclick="selectEndpoint('POST', '/api/v1/patient/exams/1/attempts', '{}')">Start Attempt (Exam ID 1)</li>
                    <li class="list-group-item endpoint-item" onclick="selectEndpoint('PUT', '/api/v1/patient/attempts/1/answers/1', '{\n  &quot;selected_option_ids&quot;: [1]\n}')">Submit Answer (Attempt 1, Q 1)</li>
                    <li class="list-group-item endpoint-item" onclick="selectEndpoint('POST', '/api/v1/patient/attempts/1/submit', '{}')">Finalize Attempt (ID 1)</li>
                    <li class="list-group-item endpoint-item" onclick="selectEndpoint('GET', '/api/v1/patient/attempts/1/result', '')">Get Attempt Result (ID 1)</li>
                    <li class="list-group-item endpoint-item" onclick="selectEndpoint('GET', '/api/v1/patient/me/attempts', '')">My History</li>
                </ul>
            </div>
        </div>

        <!-- Main Panel -->
        <div class="col-md-9 main-panel p-3">
            <div class="card mb-3 shadow-sm">
                <div class="card-header bg-light">
                    <strong>Authentication Config</strong>
                </div>
                <div class="card-body">
                    <div class="row align-items-end">
                        <div class="col-md-9">
                            <label class="form-label">Bearer Token</label>
                            <input type="text" id="bearerToken" class="form-control" placeholder="Paste Sanctum token here...">
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-secondary w-100" onclick="clearToken()">Clear Token</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3 shadow-sm">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <span id="currentEndpointTitle">Select an endpoint from the left</span>
                    <span class="badge bg-light text-dark" id="currentMethod">METHOD</span>
                </div>
                <div class="card-body">
                    <form id="requestForm">
                        <div class="mb-3">
                            <label class="form-label">URL Path</label>
                            <input type="text" id="urlPath" class="form-control" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">JSON Payload</label>
                            <textarea id="jsonPayload" class="form-control" rows="8"></textarea>
                        </div>
                        <button type="button" class="btn btn-success px-4" onclick="sendRequest()">Send Request</button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white d-flex justify-content-between">
                    <span>Response</span>
                    <span id="responseStatus" class="badge bg-secondary">Status: --</span>
                </div>
                <div class="card-body bg-dark">
                    <pre id="responseBody" class="m-0">No request sent yet.</pre>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Load token from local storage if available
    const savedToken = localStorage.getItem('api_token');
    if (savedToken) {
        document.getElementById('bearerToken').value = savedToken;
    }

    // Save token when it changes
    document.getElementById('bearerToken').addEventListener('input', function(e) {
        localStorage.setItem('api_token', e.target.value);
    });

    function clearToken() {
        document.getElementById('bearerToken').value = '';
        localStorage.removeItem('api_token');
    }

    let currentMethod = 'GET';
    let currentUrl = '';

    function selectEndpoint(method, url, payload) {
        currentMethod = method;
        currentUrl = url;
        
        document.getElementById('currentMethod').innerText = method;
        document.getElementById('currentEndpointTitle').innerText = url;
        document.getElementById('urlPath').value = url;
        document.getElementById('jsonPayload').value = payload;
        
        // Disable payload for GET
        document.getElementById('jsonPayload').disabled = (method === 'GET');
    }

    async function sendRequest() {
        const token = document.getElementById('bearerToken').value;
        const payloadText = document.getElementById('jsonPayload').value;
        let url = document.getElementById('urlPath').value;

        // Ensure URL points to the local dev server correctly.
        // It relies on relative path, so it goes to the same host/port.
        const fullUrl = window.location.origin + url;

        document.getElementById('responseStatus').innerText = 'Loading...';
        document.getElementById('responseStatus').className = 'badge bg-warning text-dark';
        document.getElementById('responseBody').innerText = 'Waiting for response...';

        const headers = {
            'Accept': 'application/json',
            'Content-Type': 'application/json'
        };

        if (token) {
            headers['Authorization'] = 'Bearer ' + token;
        }

        const options = {
            method: currentMethod,
            headers: headers
        };

        if (currentMethod !== 'GET' && payloadText.trim() !== '') {
            try {
                // Ensure it's valid JSON before sending
                JSON.parse(payloadText);
                options.body = payloadText;
            } catch (e) {
                document.getElementById('responseStatus').innerText = 'Error';
                document.getElementById('responseStatus').className = 'badge bg-danger';
                document.getElementById('responseBody').innerText = 'Invalid JSON payload:\n' + e.message;
                return;
            }
        }

        try {
            const response = await fetch(fullUrl, options);
            const status = response.status;
            
            let data;
            const contentType = response.headers.get("content-type");
            if (contentType && contentType.indexOf("application/json") !== -1) {
                data = await response.json();
            } else {
                data = await response.text();
            }

            // Auto-save token on login
            if (url === '/api/v1/auth/login' && status === 200 && data.data && data.data.token) {
                document.getElementById('bearerToken').value = data.data.token;
                localStorage.setItem('api_token', data.data.token);
            }

            // Update UI
            document.getElementById('responseStatus').innerText = `Status: ${status}`;
            document.getElementById('responseStatus').className = status >= 200 && status < 300 ? 'badge bg-success' : 'badge bg-danger';
            document.getElementById('responseBody').innerText = typeof data === 'string' ? data : JSON.stringify(data, null, 2);

        } catch (error) {
            document.getElementById('responseStatus').innerText = 'Network Error';
            document.getElementById('responseStatus').className = 'badge bg-danger';
            document.getElementById('responseBody').innerText = error.toString();
        }
    }
</script>
</body>
</html>
