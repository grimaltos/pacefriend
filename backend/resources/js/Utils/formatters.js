export function formatLapTime(ms) {
    if (!ms || ms <= 0) return '--:--.---';
    const minutes = Math.floor(ms / 60000);
    const rem = ms % 60000;
    const seconds = Math.floor(rem / 1000);
    const millis = rem % 1000;
    return `${minutes}:${String(seconds).padStart(2, '0')}.${String(millis).padStart(3, '0')}`;
}

export function formatDeltaMs(ms) {
    if (ms === null || ms === undefined) return '0.00s';
    const sec = (ms / 1000).toFixed(2);
    return ms > 0 ? `+${sec}s` : `${sec}s`;
}

export function escapeHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

export function renderMarkdown(md) {
    if (!md) return '';
    return escapeHtml(md)
        .replace(/^### (.+)$/gm, '<h3 class="text-base font-semibold text-emerald-400 mt-5 mb-1">$1</h3>')
        .replace(/^## (.+)$/gm, '<h2 class="text-lg font-bold text-white mt-6 mb-2 border-b border-zinc-800 pb-1">$1</h2>')
        .replace(/^# (.+)$/gm, '<h1 class="text-xl font-bold text-white mb-3">$1</h1>')
        .replace(/\*\*(.+?)\*\*/g, '<strong class="text-zinc-100 font-semibold">$1</strong>')
        .replace(/^- (.+)$/gm, '<li class="ml-4 list-disc text-zinc-300 my-1">$1</li>')
        .replace(/\n\n/g, '<div class="h-3"></div>');
}
