@extends('layouts.librenmsv1')

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Edit REST API Credential</h3>
            </div>
            <div class="card-body">
                <form action="{{ route('settings.rest-api.credentials.update', $credential) }}" method="POST">
                    @csrf
                    @method('PUT')
                    @include('settings.rest-api.credentials._form')
                    <button type="submit" class="btn btn-primary">Update</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
// Token reveal functionality
let tokenTimer = null;
let countdownInterval = null;
let tokenFieldTimer = null;
let tokenFieldCountdown = null;

function attachTokenRevealListeners() {
    // Session Token - API Token field
    const sessionTokenField = document.getElementById('params_api_token');
    if (sessionTokenField) {
        sessionTokenField.style.cursor = 'pointer';
        sessionTokenField.addEventListener('click', function() {
            revealToken(this);
        });
    }
    
    // Regular Token field
    const regularTokenField = document.getElementById('params_token');
    if (regularTokenField) {
        regularTokenField.style.cursor = 'pointer';
        regularTokenField.addEventListener('click', function() {
            revealTokenField(this, 'token-timer-regular');
        });
    }
}

function revealToken(input) {
    // Clear any existing timers
    if (tokenTimer) clearTimeout(tokenTimer);
    if (countdownInterval) clearInterval(countdownInterval);
    
    // Show token
    input.type = 'text';
    input.style.cursor = 'text';
    
    // Show timer
    const timerDisplay = document.getElementById('token-timer');
    const timerSeconds = document.getElementById('timer-seconds');
    if (timerDisplay && timerSeconds) {
        timerDisplay.style.display = 'flex';
        
        // Countdown
        let seconds = 5;
        timerSeconds.textContent = seconds;
        
        countdownInterval = setInterval(() => {
            seconds--;
            timerSeconds.textContent = seconds;
            
            if (seconds <= 0) {
                clearInterval(countdownInterval);
            }
        }, 1000);
        
        // Hide after 5 seconds
        tokenTimer = setTimeout(() => {
            input.type = 'password';
            input.style.cursor = 'pointer';
            timerDisplay.style.display = 'none';
            clearInterval(countdownInterval);
        }, 5000);
    }
}

function revealTokenField(input, timerId) {
    // Clear any existing timers
    if (tokenFieldTimer) clearTimeout(tokenFieldTimer);
    if (tokenFieldCountdown) clearInterval(tokenFieldCountdown);
    
    // Show token
    input.type = 'text';
    input.style.cursor = 'text';
    
    // Show timer
    const timerDisplay = document.getElementById(timerId);
    const timerSeconds = timerDisplay ? timerDisplay.querySelector('.timer-seconds') : null;
    
    if (timerDisplay && timerSeconds) {
        timerDisplay.style.display = 'flex';
        
        // Countdown
        let seconds = 5;
        timerSeconds.textContent = seconds;
        
        tokenFieldCountdown = setInterval(() => {
            seconds--;
            timerSeconds.textContent = seconds;
            
            if (seconds <= 0) {
                clearInterval(tokenFieldCountdown);
            }
        }, 1000);
        
        // Hide after 5 seconds
        tokenFieldTimer = setTimeout(() => {
            input.type = 'password';
            input.style.cursor = 'pointer';
            timerDisplay.style.display = 'none';
            clearInterval(tokenFieldCountdown);
        }, 5000);
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const authTypeSelect = document.getElementById('authentication_type_id');
    const paramsContainer = document.getElementById('auth-params-container');
    const credentialId = {{ $credential->id }};

    function fetchParams(typeId) {
        if (!typeId) {
            paramsContainer.innerHTML = '';
            return;
        }

        const url = `{{ route('settings.rest-api.credentials.params', ['typeId' => 'TYPE_ID_PLACEHOLDER']) }}`.replace('TYPE_ID_PLACEHOLDER', typeId) + `?credential_id=${credentialId}`;

        fetch(url)
            .then(response => response.text())
            .then(html => {
                paramsContainer.innerHTML = html;
                // Attach token reveal listeners after content is loaded
                setTimeout(attachTokenRevealListeners, 100);
            })
            .catch(error => console.error('Error fetching auth params:', error));
    }

    authTypeSelect.addEventListener('change', function () {
        fetchParams(this.value);
    });

    // Initial load of params
    if (authTypeSelect.value) {
        fetchParams(authTypeSelect.value);
    }
});
</script>
@endpush