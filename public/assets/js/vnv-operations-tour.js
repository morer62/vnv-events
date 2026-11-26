(() => {
  const player = document.getElementById('ops-player');
  if (!player) return;

  function readTrack(kind, onCues) {
    const element = player.querySelector('track[kind="' + kind + '"]');
    const display = () => {
      const cues = Array.from(element.track.cues || []);
      if (cues.length) onCues(cues);
    };
    element.addEventListener('load', display, { once: true });
    if (element.track.mode === 'disabled') element.track.mode = 'hidden';
    if (element.readyState === 2) display();
  }

  readTrack('chapters', cues => {
    const list = document.getElementById('ops-chapters');
    list.replaceChildren();
    for (const cue of cues) {
      const item = document.createElement('li');
      const button = document.createElement('button');
      const time = document.createElement('time');
      const seconds = Math.floor(cue.startTime);
      time.textContent = Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
      button.type = 'button';
      button.append(time, document.createTextNode(cue.text));
      button.addEventListener('click', () => {
        player.currentTime = cue.startTime;
        player.play().catch(() => player.focus());
      });
      item.append(button);
      list.append(item);
    }
    list.closest('.ops-tour__chapters').hidden = false;
  });

  readTrack('captions', cues => {
    const transcript = document.querySelector('[data-ops-transcript]');
    const paragraph = document.createElement('p');
    paragraph.textContent = cues.map(cue => cue.text.trim()).join(' ');
    transcript.replaceChildren(paragraph);
  });
})();
