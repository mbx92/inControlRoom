const serverUrlInput = document.getElementById('server-url');
const enrollmentTokenInput = document.getElementById('enrollment-token');
const saveButton = document.getElementById('save-button');
const startButton = document.getElementById('start-button');
const stopButton = document.getElementById('stop-button');
const feedback = document.getElementById('feedback');
const serviceStatus = document.getElementById('service-status');
const agentStatus = document.getElementById('agent-status');
const lastUpdated = document.getElementById('last-updated');
const statusMessage = document.getElementById('status-message');

saveButton.addEventListener('click', () => saveConfiguration());
startButton.addEventListener('click', () => controlService('/api/service/start'));
stopButton.addEventListener('click', () => controlService('/api/service/stop'));

loadConfiguration();
refreshStatus();
setInterval(refreshStatus, 5000);

async function loadConfiguration() {
  const response = await fetch('/api/config');
  const payload = await response.json();
  serverUrlInput.value = payload.server_url ?? '';
  enrollmentTokenInput.value = payload.enrollment_token ?? '';
}

async function saveConfiguration() {
  try {
    const response = await fetch('/api/config', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        server_url: serverUrlInput.value,
        enrollment_token: enrollmentTokenInput.value,
      }),
    });

    const payload = await response.json();
    if (!response.ok) {
      throw new Error(payload.message ?? 'Failed to save configuration.');
    }

    setFeedback(payload.message ?? 'Configuration saved', 'success');
  } catch (error) {
    setFeedback(error.message, 'error');
  }
}

async function controlService(path) {
  try {
    const response = await fetch(path, { method: 'POST' });
    const payload = await response.json();

    if (!response.ok) {
      throw new Error(payload.message ?? 'Service action failed.');
    }

    setFeedback(payload.message ?? 'Done', 'success');
    await refreshStatus();
  } catch (error) {
    setFeedback(error.message, 'error');
  }
}

async function refreshStatus() {
  try {
    const response = await fetch('/api/status');
    const payload = await response.json();

    serviceStatus.textContent = payload.service_status ?? '-';
    agentStatus.textContent = payload.agent_status ?? '-';
    lastUpdated.textContent = payload.last_updated ?? '-';
    statusMessage.textContent = payload.message ?? '-';
    startButton.disabled = !payload.can_start;
    stopButton.disabled = !payload.can_stop;
  } catch (error) {
    setFeedback(error.message, 'error');
  }
}

function setFeedback(message, tone) {
  feedback.textContent = message;
  feedback.style.color = tone === 'error' ? '#a02323' : '#11714b';
}
