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
