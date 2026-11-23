function initSchedule(user, view) {
  const status = document.getElementById('scheduleStatus');
  const mine = document.getElementById('mySchedule');
  const available = document.getElementById('availableSlots');
  const subject = document.getElementById('scheduleSubject');
  let slots = [];
  let month = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
  let selectedDate = '';
  const calendar = element('div', 'schedule-calendar');
  mine.before(calendar);

  function dateKey(date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
  }

  function renderCalendar() {
    calendar.replaceChildren();
    const header = element('div', 'schedule-calendar-header');
    const previous = element('button', 'plain', '‹');
    previous.type = 'button';
    previous.setAttribute('aria-label', 'Previous month');
    previous.addEventListener('click', () => { month = new Date(month.getFullYear(), month.getMonth() - 1, 1); selectedDate = ''; render(); });
    const next = element('button', 'plain', '›');
    next.type = 'button';
    next.setAttribute('aria-label', 'Next month');
    next.addEventListener('click', () => { month = new Date(month.getFullYear(), month.getMonth() + 1, 1); selectedDate = ''; render(); });
    header.append(previous, element('strong', '', month.toLocaleDateString([], { month: 'long', year: 'numeric' })), next);
    const grid = element('div', 'schedule-calendar-grid');
    ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach((day) => grid.appendChild(element('small', '', day)));
    for (let index = 0; index < month.getDay(); index++) grid.appendChild(element('span'));
    const days = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate();
    for (let day = 1; day <= days; day++) {
      const date = new Date(month.getFullYear(), month.getMonth(), day);
      const key = dateKey(date);
      const count = slots.filter((slot) => dateKey(new Date(slot.startsAt)) === key && (slot.tutorId === user.id || slot.studentId === user.id || (available && slot.status === 'open' && slot.subjects.includes(subject.value)))).length;
      const button = element('button', `schedule-day${selectedDate === key ? ' selected' : ''}${count ? ' has-events' : ''}`, String(day));
      button.type = 'button';
      button.setAttribute('aria-label', `${date.toLocaleDateString([], { dateStyle: 'full' })}, ${count} times`);
      button.setAttribute('aria-pressed', String(selectedDate === key));
      button.addEventListener('click', () => { selectedDate = selectedDate === key ? '' : key; render(); });
      grid.appendChild(button);
    }
    calendar.append(header, grid);
  }

  function makeRow(slot, own) {
    const row = element('div', 'room-item schedule-item');
    const details = element('div', 'schedule-item-details');
    const actions = element('div', 'schedule-item-actions');
    const date = new Date(slot.startsAt).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
    details.appendChild(element('strong', '', `${date} · ${slot.duration} min`));
    details.appendChild(element('small', '', slot.status === 'cancelled' ? `Cancelled${slot.subject ? ` · ${slot.subject}` : ''}` : slot.status === 'booked'
      ? `${slot.subject} · ${slot.tutorId === user.id ? slot.studentName : slot.tutorName}`
      : own ? 'Available for booking' : `${slot.tutorName} · ${slot.subjects.join(', ')}`));
    const button = element('button', own ? 'plain' : 'green', own ? 'cancel' : 'book');
    button.type = 'button';
    button.addEventListener('click', async () => {
      if (own && !confirm('Cancel this time? Both participants will see it as cancelled.')) return;
      button.disabled = true;
      try {
        const result = await api(own ? 'schedule-cancel' : 'schedule-book', own ? { slotId: slot.id } : { slotId: slot.id, subject: subject.value });
        await refresh();
        setStatus(status, result.message, 'success');
      } catch (error) {
        setStatus(status, error.message, 'error');
        button.disabled = false;
      }
    });
    row.appendChild(details);
    if (slot.status !== 'cancelled') actions.appendChild(button);
    if (own && slot.status === 'booked') {
      const enter = element('button', 'green', 'enter room');
      enter.type = 'button';
      enter.disabled = Date.now() < new Date(slot.startsAt).getTime() - 15 * 60 * 1000;
      enter.title = enter.disabled ? 'Available 15 minutes before the session' : 'Open study room';
      enter.addEventListener('click', async () => {
        enter.disabled = true;
        try {
          const result = await api('schedule-enter', { slotId: slot.id });
          location.href = `room.html?id=${result.roomId}`;
        } catch (error) {
          setStatus(status, error.message, 'error');
          enter.disabled = false;
        }
      });
      actions.appendChild(enter);
    }
    if (actions.hasChildNodes()) row.appendChild(actions);
    return row;
  }

  function render() {
    renderCalendar();
    mine.replaceChildren();
    const matchesDate = (slot) => !selectedDate || dateKey(new Date(slot.startsAt)) === selectedDate;
    const own = slots.filter((slot) => (slot.tutorId === user.id || slot.studentId === user.id) && matchesDate(slot));
    if (!own.length) mine.appendChild(element('p', '', 'No upcoming sessions or availability.'));
    own.forEach((slot) => mine.appendChild(makeRow(slot, true)));
    if (available) {
      available.replaceChildren();
      const options = slots.filter((slot) => slot.status === 'open' && slot.tutorId !== user.id && slot.subjects.includes(subject.value) && matchesDate(slot));
      if (!options.length) available.appendChild(element('p', '', 'No available times for this subject yet.'));
      options.forEach((slot) => available.appendChild(makeRow(slot, false)));
    }
  }

  async function refresh() {
    try {
      slots = (await api('schedule-list')).slots;
      render();
    } catch (error) {
      setStatus(status, error.message, 'error');
    }
  }

  if (subject) {
    user.availableSubjects.forEach((name) => subject.add(new Option(name, name)));
    subject.addEventListener('change', render);
  }
  const form = document.getElementById('slotForm');
  if (form) form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const starts = new Date(document.getElementById('slotStart').value);
    if (Number.isNaN(starts.getTime())) return setStatus(status, 'Choose a date and time.', 'error');
    try {
      const result = await api('schedule-add', { startsAt: starts.toISOString(), duration: Number(document.getElementById('slotDuration').value) });
      form.reset();
      await refresh();
      setStatus(status, result.message, 'success');
    } catch (error) {
      setStatus(status, error.message, 'error');
    }
  });
  refresh();
  setInterval(refresh, 30000);
}