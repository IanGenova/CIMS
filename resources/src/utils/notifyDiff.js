// Which notices pop up: the unread ones that arrived since the last look.
//
// `lastSeenId` is null on the first look after the app opens; that look only
// sets the starting point, so a backlog stays in the bell rather than bursting
// into pop-ups. The mark never moves backwards. See tests/js/notifyDiff.test.js.
export function arrivals(list, lastSeenId) {
  const items = list || [];
  const top = items.reduce((m, n) => Math.max(m, n.id), lastSeenId === null ? 0 : lastSeenId);

  if (lastSeenId === null) {
    return { fresh: [], lastSeenId: top };
  }

  const fresh = items
    .filter(n => !n.is_read && n.id > lastSeenId)
    .sort((a, b) => a.id - b.id);

  return { fresh, lastSeenId: top };
}

// When a notice happened, in words: "Just now", "8 min ago", "2 hr ago",
// "Yesterday 8:00 AM", "Sep 29". The server sends "YYYY-MM-DD HH:MM:SS" in the
// shop's own time, read here as local time.
export function whenSaid(stamp, now) {
  if (!stamp) {
    return '';
  }
  const p = String(stamp).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/);
  const at = p ? new Date(+p[1], +p[2] - 1, +p[3], +p[4], +p[5], +(p[6] || 0)) : new Date(stamp);
  if (isNaN(at)) {
    return '';
  }
  const ref = now || new Date();
  const minutes = Math.floor((ref - at) / 60000);
  const time = at.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });

  if (minutes < 1) {
    return 'Just now';
  }
  if (minutes < 60) {
    return minutes + ' min ago';
  }
  const startOfToday = new Date(ref.getFullYear(), ref.getMonth(), ref.getDate());
  if (at >= startOfToday) {
    return Math.floor(minutes / 60) + ' hr ago';
  }
  if (at >= new Date(startOfToday - 86400000)) {
    return 'Yesterday ' + time;
  }
  return at.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ', ' + time;
}
