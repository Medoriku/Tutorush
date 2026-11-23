function initSchedule(user, view) {
  const status = document.getElementById('scheduleStatus');
  const mine = document.getElementById('mySchedule');
  const available = document.getElementById('availableSlots');
  const subject = document.getElementById('scheduleSubject');
  let slots = [];
  let clockOffset = 0;
  let month = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
  let selectedDate = '';
  const calendar = element('div', 'schedule-calendar');
  mine.before(calendar);

  function dateKey(date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
  }

  function localInputValue(date) {
    return `${dateKey(date)}T${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
  }

  function timesFor(slot, duration) {
    const starts = new Date(slot.startsAt).getTime();
    const ends = starts + slot.duration * 60000;
    const earliest = Date.now() + clockOffset + 7200000;
    const times = [];
    for (let time = starts; time + duration * 60000 <= ends; time += 1800000) {
      if (time < earliest || (selectedDate && dateKey(new Date(time)) !== selectedDate)) continue;
      const overlaps = (slot.busy || []).some((busy) => {
        const busyStart = new Date(busy.startsAt).getTime();
        return time < busyStart + busy.duration * 60000 && time + duration * 60000 > busyStart;
      });
      const personalOverlap = slots.some((entry) => {
        if (entry.status !== 'booked' || (entry.studentId !== user.id && entry.tutorId !== user.id)) return false;
        const entryStart = new Date(entry.startsAt).getTime();
        return time < entryStart + entry.duration * 60000 && time + duration * 60000 > entryStart;
      });
      if (!overlaps && !personalOverlap) times.push(time);
    }
    return times;
  }

  function overlapsDay(slot, date) {
    const start = new Date(slot.startsAt).getTime();
    const dayStart = new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime();
    const dayEnd = new Date(date.getFullYear(), date.getMonth(), date.getDate() + 1).getTime();
    return start < dayEnd && start + slot.duration * 60000 > dayStart;
  }

  function renderCalendar() {
    calendar.replaceChildren();
    const header = element('div', 'schedule-calendar-header');
    const previous = element('button', 'plain', '‹');
    previous.type = 'button';
    previous.setAttribute('aria-label', 'Previous month');
    const today = new Date(Date.now() + clockOffset);
    previous.disabled = month.getFullYear() === today.getFullYear() && month.getMonth() <= today.getMonth();
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
      const count = slots.filter((slot) => overlapsDay(slot, date) && (slot.tutorId === user.id || slot.studentId === user.id || (available && slot.status === 'open' && slot.subjects.includes(subject.value)))).length;
      const button = element('button', `schedule-day${selectedDate === key ? ' selected' : ''}${count ? ' has-events' : ''}`, String(day));
      button.type = 'button';
      button.disabled = key < dateKey(today);
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
    const end = new Date(new Date(slot.startsAt).getTime() + slot.duration * 60000).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
    details.appendChild(element('strong', '', slot.status === 'open' ? `${date} – ${end}` : `${date} · ${slot.duration} min`));
    details.appendChild(element('small', '', slot.status === 'cancelled' ? `Cancelled${slot.subject ? ` · ${slot.subject}` : ''}` : slot.status === 'booked'
      ? `${slot.subject} · ${slot.tutorId === user.id ? slot.studentName : slot.tutorName}`
      : own ? 'Available for booking' : `${slot.tutorName} · ${slot.subjects.join(', ')}`));
    const button = element('button', own ? 'plain' : 'green', own ? 'cancel' : 'book');
    button.type = 'button';
    let startSelect;
    let durationSelect;
    if (!own) {
      const booking = element('div', 'schedule-booking');
      const durationLabel = element('label', '', 'Session length');
      durationSelect = element('select');
      durationSelect.setAttribute('aria-label', `Session length with ${slot.tutorName}`);
      [30, 60, 90, 120].forEach((minutes) => durationSelect.add(new Option(`${minutes} minutes`, String(minutes))));
      durationLabel.appendChild(durationSelect);
      const startLabel = element('label', '', 'Start time');
      startSelect = element('select');
      startSelect.setAttribute('aria-label', `Start time with ${slot.tutorName}`);
      startLabel.appendChild(startSelect);
      const updateTimes = () => {
        const choices = timesFor(slot, Number(durationSelect.value));
        startSelect.replaceChildren(...choices.map((time) => new Option(new Date(time).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }), new Date(time).toISOString())));
        button.disabled = choices.length === 0;
        if (!choices.length) startSelect.add(new Option('No available times', ''));
      };
      durationSelect.addEventListener('change', updateTimes);
      booking.append(durationLabel, startLabel);
      details.appendChild(booking);
      updateTimes();
    }
    button.addEventListener('click', async () => {
      if (own && !confirm('Cancel this time? Both participants will see it as cancelled.')) return;
      button.disabled = true;
      try {
        const result = await api(own ? 'schedule-cancel' : 'schedule-book', own ? { slotId: slot.id } : { slotId: slot.id, subject: subject.value, startsAt: startSelect.value, duration: Number(durationSelect.value) });
        await refresh();
        setStatus(status, result.message, 'success');
      } catch (error) {
        setStatus(status, error.message, 'error');
        button.disabled = false;
      }
    });
    row.appendChild(details);
    if (slot.status !== 'cancelled' && (!own || slot.status === 'open' || new Date(slot.startsAt).getTime() > Date.now() + clockOffset)) actions.appendChild(button);
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
    const form = document.getElementById('slotForm');
    if (form) {
      const selected = document.getElementById('availabilityDate');
      selected.textContent = selectedDate ? `Availability for ${new Date(`${selectedDate}T12:00:00`).toLocaleDateString([], { dateStyle: 'full' })}` : 'Select a date on the calendar.';
      form.querySelector('button[type="submit"]').disabled = !selectedDate;
    }
    mine.replaceChildren();
    const matchesDate = (slot) => !selectedDate || overlapsDay(slot, new Date(`${selectedDate}T00:00:00`));
    const own = slots.filter((slot) => (slot.tutorId === user.id || slot.studentId === user.id) && matchesDate(slot));
    if (!own.length) mine.appendChild(element('p', '', 'No upcoming sessions or availability.'));
    own.forEach((slot) => mine.appendChild(makeRow(slot, true)));
    if (available) {
      available.replaceChildren();
      const options = slots.filter((slot) => slot.status === 'open' && slot.tutorId !== user.id && slot.subjects.includes(subject.value) && matchesDate(slot) && timesFor(slot, 30).length);
      if (!options.length) available.appendChild(element('p', '', 'No available times for this subject yet.'));
      options.forEach((slot) => available.appendChild(makeRow(slot, false)));
    }
  }

  async function refresh() {
    try {
      const result = await api('schedule-list');
      slots = result.slots;
      clockOffset = result.now ? new Date(result.now).getTime() - Date.now() : 0;
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
  if (form) {
    const updateEndHint = () => {
      const start = document.getElementById('slotStart').value;
      const end = document.getElementById('slotEnd').value;
      document.getElementById('endTimeHint').textContent = start && end && end < start ? 'Ends the following day' : '';
    };
    document.getElementById('slotStart').addEventListener('input', updateEndHint);
    document.getElementById('slotEnd').addEventListener('input', updateEndHint);
  }
  if (form) form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!selectedDate) return setStatus(status, 'Select a date on the calendar.', 'error');
    const startTime = document.getElementById('slotStart').value;
    const endTime = document.getElementById('slotEnd').value;
    const starts = new Date(`${selectedDate}T${startTime}`);
    const endDay = new Date(`${selectedDate}T12:00:00`);
    if (endTime < startTime) endDay.setDate(endDay.getDate() + 1);
    const endDate = dateKey(endDay);
    const ends = new Date(`${endDate}T${endTime}`);
    if (Number.isNaN(starts.getTime()) || Number.isNaN(ends.getTime())) return setStatus(status, 'Choose a start and end time.', 'error');
    if (localInputValue(starts) !== `${selectedDate}T${startTime}` || localInputValue(ends) !== `${endDate}T${endTime}`) return setStatus(status, 'That local time does not exist. Choose another time.', 'error');
    if (starts.getTime() <= Date.now() + clockOffset || ends <= starts) return setStatus(status, 'Choose a future start and a later end time.', 'error');
    try {
      const result = await api('schedule-add', { startsAt: starts.toISOString(), endsAt: ends.toISOString() });
      form.reset();
      document.getElementById('endTimeHint').textContent = '';
      await refresh();
      setStatus(status, result.message, 'success');
    } catch (error) {
      setStatus(status, error.message, 'error');
    }
  });
  refresh();
  setInterval(refresh, 30000);
}