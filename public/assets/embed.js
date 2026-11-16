/* Click-to-load video players (2026-11-08): progressive enhancement of `.bb-embed` cards. Nothing is requested
   from YouTube or Vimeo until the reader presses the button; the players come from youtube-nocookie.com and
   player.vimeo.com (dnt=1). Labels come from the page (#embed-labels), so the script holds no UI text. */
(() => {
    'use strict';
    const labels = document.getElementById('embed-labels');
    if (!labels) {
        return;
    }
    const providers = {
        youtube: { name: 'YouTube', src: id => 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1' },
        vimeo: { name: 'Vimeo', src: id => 'https://player.vimeo.com/video/' + id + '?autoplay=1&dnt=1' },
    };
    document.querySelectorAll('.bb-embed').forEach(card => {
        const provider = providers[card.dataset.embed];
        const id = card.dataset.embedId || '';
        if (!provider || !/^[A-Za-z0-9_-]{1,20}$/.test(id)) {
            return;
        }
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'bb-embed-play';
        button.textContent = labels.dataset.play + ' · ' + provider.name;
        const note = document.createElement('span');
        note.className = 'bb-embed-note';
        note.textContent = labels.dataset.notice;
        button.addEventListener('click', () => {
            const frame = document.createElement('iframe');
            frame.src = provider.src(id);
            frame.title = provider.name;
            frame.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
            frame.setAttribute('allowfullscreen', '');
            frame.referrerPolicy = 'strict-origin-when-cross-origin';
            frame.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation allow-popups');
            card.replaceChildren(frame);
            card.classList.add('bb-embed-active');
        });
        card.prepend(button, note);
    });
})();
