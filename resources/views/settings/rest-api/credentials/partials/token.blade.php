<div class="form-group">
    <label for="params_token">Token</label>
    <div class="input-group">
        <input type="password" 
               name="params[token]" 
               id="params_token" 
               class="form-control" 
               value="{{ old('params.token', $credential->params->firstWhere('key', 'token')->value ?? '') }}" 
               onclick="revealTokenField(this, 'token-timer-regular')" 
               readonly 
               onfocus="this.removeAttribute('readonly');"
               required>
        <div class="input-group-append">
            <span class="input-group-text" id="token-timer-regular" style="display: none;">
                <i class="fas fa-clock"></i> <span class="timer-seconds">5</span>s
            </span>
        </div>
    </div>
    <small class="form-text text-muted">
        <i class="fas fa-info-circle"></i> Click the field to reveal the token for 5 seconds.
    </small>
</div>

<script>
let tokenFieldTimer = null;
let tokenFieldCountdown = null;

function revealTokenField(input, timerId) {
    // Clear any existing timers
    if (tokenFieldTimer) clearTimeout(tokenFieldTimer);
    if (tokenFieldCountdown) clearInterval(tokenFieldCountdown);
    
    // Show token
    input.type = 'text';
    
    // Show timer
    const timerDisplay = document.getElementById(timerId);
    const timerSeconds = timerDisplay.querySelector('.timer-seconds');
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
        timerDisplay.style.display = 'none';
        clearInterval(tokenFieldCountdown);
    }, 5000);
}
</script>

<div class="form-group">
    <label for="params_header">Header Name</label>
    <input type="text" name="params[header]" id="params_header" class="form-control" value="{{ old('params.header', $credential->params->firstWhere('key', 'header')->value ?? 'Authorization') }}" required>
    <small class="form-text text-muted">The name of the HTTP header to use for the token (e.g., Authorization, X-API-Key).</small>
</div>

<div class="form-group">
    <label for="params_scheme">Scheme</label>
    <input type="text" name="params[scheme]" id="params_scheme" class="form-control" value="{{ old('params.scheme', $credential->params->firstWhere('key', 'scheme')->value ?? 'Bearer') }}">
    <small class="form-text text-muted">The authentication scheme to prepend to the token (e.g., Bearer, Token). Leave blank if not needed.</small>
</div>