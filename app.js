const routes = { student: 'student-dashboard.html', tutor: 'tutor-dashboard.html', admin: 'admin-dashboard.html' };
let csrfToken = '';

async function api(action, payload = {}) {
  const response = await fetch(`tutoring-api.php?action=${action}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
    body: JSON.stringify({ action, ...payload }),
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(data.error || 'Request failed');
  return data;
}

async function loadSession() {
  const response = await fetch('tutoring-api.php?action=session', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'session' }),
  });
  const session = await response.json();
  if (!session.user) {
    location.replace('login.html');
    return null;
  }
  csrfToken = session.csrfToken;
  return session.user;
}

function setupHeader(user, current) {
  const switcher = document.getElementById('viewSwitcher');
  if (switcher) {
    const roles = user.role === 'admin' ? ['student', 'tutor', 'admin'] : user.role === 'both' ? ['student', 'tutor'] : [current];
    const labels = { student: 'Student dashboard', tutor: 'Tutor dashboard', admin: 'Admin dashboard' };
    roles.forEach((role) => switcher.add(new Option(labels[role], role)));
    switcher.value = current;
    switcher.addEventListener('change', () => { location.href = routes[switcher.value]; });
  }
  const logout = document.getElementById('logout');
  if (logout) logout.addEventListener('click', () => { location.href = 'login.html'; });
}

function setStatus(element, message, type = '') {
  element.textContent = message;
  element.className = `status ${type}`.trim();
}

function formatDateTime(iso) {
  return iso ? new Date(iso).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '';
}

function formatTime(iso) {
  return new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

function element(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function renderRoomList(container, rooms) {
  container.replaceChildren();
  if (!rooms.length) {
    container.appendChild(element('p', '', 'No study rooms yet. Rooms open when a session is accepted and stay here as history.'));
    return;
  }
  rooms.forEach((room) => {
    const link = element('a', 'room-item');
    link.href = `room.html?id=${room.id}`;
    const info = element('div');
    info.appendChild(element('strong', '', `${room.subject} with ${room.partnerName}`));
    const when = room.status === 'ended' ? `Ended ${formatDateTime(room.endedAt)}` : `Started ${formatDateTime(room.createdAt)}`;
    info.appendChild(element('small', '', `${room.helpType} · ${when}`));
    link.append(info, element('span', `badge ${room.status}`, room.status === 'active' ? 'ACTIVE' : 'ENDED'));
    container.appendChild(link);
  });
}

let audioContext = null;

function soundsEnabled() {
  return localStorage.getItem('tutorrush-sounds') !== 'off';
}

function setSoundsEnabled(on) {
  localStorage.setItem('tutorrush-sounds', on ? 'on' : 'off');
}

function playTone(kind) {
  if (!soundsEnabled()) return;
  try {
    audioContext = audioContext || new (window.AudioContext || window.webkitAudioContext)();
    if (audioContext.state === 'suspended') audioContext.resume();
    const notes = {
      message: [[880, 0, 0.09]],
      join: [[523, 0, 0.12], [784, 0.13, 0.18]],
      leave: [[784, 0, 0.12], [523, 0.13, 0.18]],
    }[kind] || [];
    const start = audioContext.currentTime;
    notes.forEach(([frequency, offset, length]) => {
      const oscillator = audioContext.createOscillator();
      const gain = audioContext.createGain();
      oscillator.frequency.value = frequency;
      gain.gain.setValueAtTime(0.0001, start + offset);
      gain.gain.exponentialRampToValueAtTime(0.15, start + offset + 0.01);
      gain.gain.exponentialRampToValueAtTime(0.0001, start + offset + length);
      oscillator.connect(gain).connect(audioContext.destination);
      oscillator.start(start + offset);
      oscillator.stop(start + offset + length + 0.02);
    });
  } catch (error) {
    // Sounds are optional; browsers may block audio before the first interaction.
  }
}

function toast(message) {
  let box = document.getElementById('toasts');
  if (!box) {
    box = element('div', 'toasts');
    box.id = 'toasts';
    box.setAttribute('aria-live', 'polite');
    document.body.appendChild(box);
  }
  const item = element('div', 'toast', message);
  box.appendChild(item);
  setTimeout(() => item.remove(), 4500);
}

function initFeedback(roomId = null) {
  const actions = document.querySelector('header.top .actions');
  if (!actions) return;
  const open = element('button', 'plain', 'report an issue');
  open.type = 'button';

  const dialog = document.createElement('dialog');
  dialog.className = 'feedback-dialog';
  const form = document.createElement('form');
  form.method = 'dialog';
  const categoryField = element('div', 'field');
  const category = element('select');
  [['bug', 'Something is broken'], ['video', 'Video call problem'], ['chat', 'Chat problem'], ['files', 'File problem'], ['suggestion', 'Suggestion'], ['other', 'Other']]
    .forEach(([value, label]) => category.add(new Option(label, value)));
  category.id = 'feedbackCategory';
  const categoryLabel = element('label', '', 'What is this about?');
  categoryLabel.htmlFor = category.id;
  categoryField.append(categoryLabel, category);

  const messageField = element('div', 'field');
  const message = element('textarea');
  message.id = 'feedbackMessage';
  message.maxLength = 1000;
  message.placeholder = 'Tell us what happened or what we could improve...';
  const messageLabel = element('label', '', 'Details');
  messageLabel.htmlFor = message.id;
  messageField.append(messageLabel, message);

  const result = element('p', 'status');
  const buttons = element('div', 'dialog-actions');
  const cancel = element('button', 'plain', 'cancel');
  cancel.type = 'button';
  const send = element('button', 'green', 'send report');
  send.type = 'submit';
  buttons.append(cancel, send);
  form.append(element('h2', '', 'Report an issue'), element('p', '', 'Your report goes to the TutorRush admins.'), categoryField, messageField, result, buttons);
  dialog.appendChild(form);

  open.addEventListener('click', () => {
    message.value = '';
    setStatus(result, '');
    dialog.showModal();
  });
  cancel.addEventListener('click', () => dialog.close());
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!message.value.trim()) return setStatus(result, 'Please describe the issue.', 'error');
    send.disabled = true;
    try {
      const data = await api('submit-feedback', { category: category.value, message: message.value.trim(), page: location.pathname.split('/').pop(), roomId });
      setStatus(result, data.message, 'success');
      setTimeout(() => dialog.close(), 1400);
    } catch (error) {
      setStatus(result, error.message, 'error');
    } finally {
      send.disabled = false;
    }
  });
  actions.prepend(open);
  document.body.appendChild(dialog);
}
