<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Exam Builder</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; padding-bottom: 50px; }
        .section-card { margin-bottom: 20px; border-left: 4px solid #0d6efd; }
        .subscale-card { border-left: 4px solid #198754; margin-bottom: 15px; }
        .question-card { border-left: 4px solid #ffc107; margin-bottom: 15px; margin-left: 20px; }
        .option-row { margin-left: 20px; margin-bottom: 5px; }
        .band-card { border-left: 4px solid #dc3545; margin-bottom: 10px; }
        .remove-btn { cursor: pointer; color: red; font-weight: bold; }
    </style>
</head>
<body>
<div class="container mt-4">
    <h2 class="mb-4">إليك - Admin Exam Builder</h2>

    <!-- Auth Section -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-dark text-white">Authentication (Admin)</div>
        <div class="card-body row align-items-end">
            <div class="col-md-8">
                <label>Bearer Token</label>
                <input type="text" id="adminToken" class="form-control" placeholder="Enter admin token or use login below...">
            </div>
            <div class="col-md-4">
                <button class="btn btn-outline-secondary w-100 mb-2" onclick="loginAdmin()">Auto-Login as Admin (admin@test.com)</button>
            </div>
        </div>
    </div>

    <hr>

    <!-- Disorder Builder -->
    <div class="card shadow-sm section-card mb-4" style="border-left-color: #6f42c1;">
        <div class="card-header bg-light"><strong>0. Create New Disorder</strong> (Optional)</div>
        <div class="card-body">
            <form id="disorderForm" onsubmit="event.preventDefault(); submitDisorder();">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label>Name (English)</label>
                        <input type="text" id="new_disorder_name" class="form-control" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label>Name (Arabic)</label>
                        <input type="text" id="new_disorder_name_ar" class="form-control" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">Create Disorder</button>
                    </div>
                </div>
            </form>
            <div id="disorderAlert" class="alert d-none mt-2 mb-0"></div>
        </div>
    </div>

    <form id="examForm" onsubmit="event.preventDefault(); submitExam();">
        
        <!-- Exam Details -->
        <div class="card shadow-sm section-card">
            <div class="card-header bg-light"><strong>1. Exam Details</strong></div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label>Disorder</label>
                        <select id="disorder_id" class="form-select" required>
                            <option value="">Loading disorders...</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label>Exam Name (English)</label>
                        <input type="text" id="exam_name" class="form-control" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label>Exam Name (Arabic)</label>
                        <input type="text" id="exam_name_ar" class="form-control" required>
                    </div>
                    <div class="col-md-12 mb-3">
                        <label>Description</label>
                        <textarea id="exam_description" class="form-control" rows="2"></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Subscales -->
        <div class="card shadow-sm section-card">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <strong>2. Subscales</strong>
                <button type="button" class="btn btn-sm btn-success" onclick="addSubscale()">+ Add Subscale</button>
            </div>
            <div class="card-body" id="subscalesContainer">
                <!-- Subscales injected here -->
            </div>
        </div>

        <!-- Questions -->
        <div class="card shadow-sm section-card">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <strong>3. Questions</strong>
                <button type="button" class="btn btn-sm btn-warning" onclick="addQuestion()">+ Add Question</button>
            </div>
            <div class="card-body" id="questionsContainer">
                <!-- Questions injected here -->
            </div>
        </div>

        <!-- Severity Bands -->
        <div class="card shadow-sm section-card">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <strong>4. Exam Total Severity Bands</strong>
                <button type="button" class="btn btn-sm btn-danger" onclick="addBand()">+ Add Band</button>
            </div>
            <div class="card-body" id="bandsContainer">
                <!-- Bands injected here -->
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100 shadow">Create Exam</button>
    </form>

    <div id="resultAlert" class="mt-4 alert d-none"></div>
</div>

<script>
    let subscaleCount = 0;
    let questionCount = 0;
    let bandCount = 0;

    const tokenInput = document.getElementById('adminToken');
    if (localStorage.getItem('admin_token')) {
        tokenInput.value = localStorage.getItem('admin_token');
    }
    tokenInput.addEventListener('input', e => localStorage.setItem('admin_token', e.target.value));

    async function loginAdmin() {
        const res = await fetch('/api/v1/auth/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ email: 'admin@test.com', password: 'password' })
        });
        const data = await res.json();
        if (data.data?.token) {
            tokenInput.value = data.data.token;
            localStorage.setItem('admin_token', data.data.token);
            alert('Admin logged in successfully!');
            loadDisorders();
        } else {
            alert('Login failed. Did you run the UserSeeder?');
        }
    }

    async function loadDisorders() {
        if (!tokenInput.value) return;
        try {
            const res = await fetch('/api/v1/disorders', {
                headers: { 'Authorization': 'Bearer ' + tokenInput.value, 'Accept': 'application/json' }
            });
            const data = await res.json();
            const select = document.getElementById('disorder_id');
            select.innerHTML = '';
            if (data.data) {
                data.data.forEach(d => {
                    const opt = document.createElement('option');
                    opt.value = d.id;
                    opt.textContent = `${d.name} (${d.name_ar})`;
                    select.appendChild(opt);
                });
            }
        } catch (e) {
            console.error('Failed to load disorders', e);
        }
    }

    async function submitDisorder() {
        if (!tokenInput.value) {
            alert('Please login as admin first!'); return;
        }
        
        const payload = {
            name: document.getElementById('new_disorder_name').value,
            name_ar: document.getElementById('new_disorder_name_ar').value,
        };
        
        try {
            const res = await fetch('/api/v1/disorders', {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer ' + tokenInput.value,
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(payload)
            });
            
            const alertBox = document.getElementById('disorderAlert');
            alertBox.classList.remove('d-none', 'alert-success', 'alert-danger');
            
            if (res.ok) {
                alertBox.classList.add('alert-success');
                alertBox.innerHTML = `Disorder created! Refreshing dropdown...`;
                document.getElementById('disorderForm').reset();
                loadDisorders();
                setTimeout(() => alertBox.classList.add('d-none'), 3000);
            } else {
                const data = await res.json();
                alertBox.classList.add('alert-danger');
                alertBox.innerHTML = `<strong>Error:</strong> <pre class="mb-0 mt-2">${JSON.stringify(data, null, 2)}</pre>`;
            }
        } catch (e) {
            alert('Request failed: ' + e);
        }
    }

    function addSubscale() {
        const id = subscaleCount++;
        const html = `
            <div class="card subscale-card p-3" id="subscale_${id}">
                <div class="d-flex justify-content-between">
                    <h5>Subscale Index: ${id}</h5>
                    <span class="remove-btn" onclick="document.getElementById('subscale_${id}').remove(); updateSubscaleDropdowns();">X Remove</span>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-2">
                        <input type="text" class="form-control subscale-name" placeholder="Name (e.g. Obsessions)" required>
                    </div>
                    <div class="col-md-6 mb-2">
                        <input type="text" class="form-control subscale-name-ar" placeholder="Arabic Name" required>
                    </div>
                </div>
            </div>
        `;
        document.getElementById('subscalesContainer').insertAdjacentHTML('beforeend', html);
        updateSubscaleDropdowns();
    }

    function updateSubscaleDropdowns() {
        const options = Array.from(document.querySelectorAll('.subscale-card')).map((card, idx) => {
            return `<option value="${idx}">Subscale ${idx}</option>`;
        }).join('');
        document.querySelectorAll('.q-subscale-select').forEach(sel => {
            const currentVal = sel.value;
            sel.innerHTML = options;
            if (currentVal) sel.value = currentVal;
        });
    }

    function addQuestion() {
        const qId = questionCount++;
        const html = `
            <div class="card question-card p-3" id="question_${qId}">
                <div class="d-flex justify-content-between">
                    <h5>Question</h5>
                    <span class="remove-btn" onclick="document.getElementById('question_${qId}').remove()">X Remove</span>
                </div>
                <div class="row mb-2">
                    <div class="col-md-3">
                        <label>Assign to Subscale</label>
                        <select class="form-select q-subscale-select" required></select>
                    </div>
                    <div class="col-md-3">
                        <label>Type</label>
                        <select class="form-select q-type" required>
                            <option value="single_select">Single Select</option>
                            <option value="multi_select">Multi Select</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label>Text</label>
                        <input type="text" class="form-control q-text" required>
                    </div>
                    <div class="col-md-3">
                        <label>Arabic Text</label>
                        <input type="text" class="form-control q-text-ar" required>
                    </div>
                </div>
                <div class="mt-2">
                    <h6>Options <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="addOption(${qId})">+ Add Option</button></h6>
                    <div id="options_container_${qId}"></div>
                </div>
            </div>
        `;
        document.getElementById('questionsContainer').insertAdjacentHTML('beforeend', html);
        updateSubscaleDropdowns();
    }

    function addOption(qId) {
        const html = `
            <div class="row option-row align-items-center">
                <div class="col-md-4"><input type="text" class="form-control form-control-sm opt-text" placeholder="Option Text" required></div>
                <div class="col-md-4"><input type="text" class="form-control form-control-sm opt-text-ar" placeholder="Arabic Option Text" required></div>
                <div class="col-md-3"><input type="number" class="form-control form-control-sm opt-score" placeholder="Score (e.g. 1)" required></div>
                <div class="col-md-1"><span class="remove-btn" onclick="this.parentElement.parentElement.remove()">X</span></div>
            </div>
        `;
        document.getElementById(`options_container_${qId}`).insertAdjacentHTML('beforeend', html);
    }

    function addBand() {
        const html = `
            <div class="card band-card p-3 row flex-row align-items-center">
                <div class="col-md-3"><input type="number" class="form-control band-min" placeholder="Min Score" required></div>
                <div class="col-md-3"><input type="number" class="form-control band-max" placeholder="Max Score" required></div>
                <div class="col-md-5"><input type="text" class="form-control band-label" placeholder="Label (e.g. Severe)" required></div>
                <div class="col-md-1"><span class="remove-btn" onclick="this.parentElement.parentElement.remove()">X</span></div>
            </div>
        `;
        document.getElementById('bandsContainer').insertAdjacentHTML('beforeend', html);
    }

    async function submitExam() {
        if (!tokenInput.value) {
            alert('Please login as admin first!'); return;
        }

        const payload = {
            disorder_id: document.getElementById('disorder_id').value,
            name: document.getElementById('exam_name').value,
            name_ar: document.getElementById('exam_name_ar').value,
            description: document.getElementById('exam_description').value,
            is_active: true,
            subscales: [],
            questions: [],
            bands: []
        };

        // Gather Subscales
        document.querySelectorAll('.subscale-card').forEach(card => {
            payload.subscales.push({
                name: card.querySelector('.subscale-name').value,
                name_ar: card.querySelector('.subscale-name-ar').value
            });
        });

        // Gather Questions
        document.querySelectorAll('.question-card').forEach(card => {
            const q = {
                subscale_index: parseInt(card.querySelector('.q-subscale-select').value),
                type: card.querySelector('.q-type').value,
                text: card.querySelector('.q-text').value,
                text_ar: card.querySelector('.q-text-ar').value,
                options: []
            };
            card.querySelectorAll('.option-row').forEach(row => {
                q.options.push({
                    text: row.querySelector('.opt-text').value,
                    text_ar: row.querySelector('.opt-text-ar').value,
                    score: parseInt(row.querySelector('.opt-score').value)
                });
            });
            payload.questions.push(q);
        });

        // Gather Bands
        document.querySelectorAll('.band-card').forEach(card => {
            payload.bands.push({
                min_score: parseInt(card.querySelector('.band-min').value),
                max_score: parseInt(card.querySelector('.band-max').value),
                label: card.querySelector('.band-label').value
            });
        });

        try {
            const res = await fetch('/api/v1/exams', {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer ' + tokenInput.value,
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(payload)
            });
            
            const data = await res.json();
            const alertBox = document.getElementById('resultAlert');
            alertBox.classList.remove('d-none', 'alert-success', 'alert-danger');

            if (res.ok) {
                alertBox.classList.add('alert-success');
                alertBox.innerHTML = `<strong>Success!</strong> Exam created with ID: ${data.data.id}`;
            } else {
                alertBox.classList.add('alert-danger');
                alertBox.innerHTML = `<strong>Error:</strong> <pre>${JSON.stringify(data, null, 2)}</pre>`;
            }
        } catch (e) {
            alert('Request failed: ' + e);
        }
    }

    // Auto-load disorders on startup if token exists
    if (tokenInput.value) {
        loadDisorders();
    }
</script>
</body>
</html>
