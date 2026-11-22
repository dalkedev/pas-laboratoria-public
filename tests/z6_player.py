from pathlib import Path
import shutil
import subprocess
import sys


SCRIPT = r'''
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(process.argv.at(-1), 'utf8');
let assertions = 0;
const check = (value, message) => { assert.ok(value, message); assertions += 1; };

class Element {
    constructor() { this.listeners = new Map(); this.dataset = {}; this.attributes = new Map(); this.textContent = ''; this.disabled = false; }
    addEventListener(type, listener) { this.listeners.set(type, [...(this.listeners.get(type) || []), listener]); }
    dispatchEvent(event) { for (const listener of this.listeners.get(event.type) || []) listener(event); }
    fire(type) { this.dispatchEvent({type}); }
    setAttribute(key, value) { this.attributes.set(key, String(value)); }
    getAttribute(key) { return this.attributes.get(key); }
    remove() { this.parent.children = this.parent.children.filter(item => item !== this); }
}

const make = (kind, queue) => {
    const root = new Element();
    const media = new Element();
    Object.assign(media, {tagName: kind.toUpperCase(), paused: true, ended: false, currentTime: 0, children: [], playCalls: 0, loadCalls: 0, rejectPlayback: false});
    media.pause = () => { if (!media.paused) { media.paused = true; media.fire('pause'); } };
    media.play = () => { media.playCalls += 1; if (media.rejectPlayback) return Promise.reject(new Error('blocked')); media.paused = false; media.ended = false; media.fire('play'); return Promise.resolve(); };
    media.load = () => { media.loadCalls += 1; media.currentTime = 0; media.ended = false; };
    media.append = item => { item.parent = media; media.children.push(item); };
    media.querySelectorAll = selector => selector === 'track' ? media.children : [];
    const nodes = new Map([['[data-player-media]', media]]);
    for (const name of ['queue', 'title', 'artist', 'status', 'text', 'prev', 'next', 'toggle', 'repeat']) nodes.set(`[data-player-${name}]`, new Element());
    nodes.get('[data-player-queue]').textContent = typeof queue === 'string' ? queue : JSON.stringify(queue);
    const buttons = [1, 2].map(id => { const button = new Element(); button.dataset.trackId = String(id); return button; });
    root.querySelector = selector => nodes.get(selector);
    root.querySelectorAll = selector => selector === '[data-track-id]' ? buttons : [];
    const document = {readyState: 'complete', querySelectorAll: () => [root], querySelector: () => null, createElement: () => new Element()};
    const context = vm.createContext({window: {location: {href: 'http://127.0.0.1:8081/z6a/', origin: 'http://127.0.0.1:8081'}}, document, URL, CustomEvent: class {constructor(type, options) {this.type = type; Object.assign(this, options);}}});
    vm.runInContext(source, context);
    return {root, media, nodes, buttons, context};
};

const settle = () => new Promise(resolve => setImmediate(resolve));
for (const kind of ['audio', 'video']) {
    const firstTitle = '<img src=x onerror=alert(1)>';
    const fixture = make(kind, [
        {id: 1, title: firstTitle, url: '/z6a/stream.php?id=1', lyrics: '<b>First lyrics</b>', subtitleUrl: '/z6b/subtitles.php?id=1'},
        {id: 2, title: 'Second item', url: '/z6a/stream.php?id=2', lyrics: 'Second lyrics', subtitleUrl: '/z6b/subtitles.php?id=2'},
        {id: 3, title: 'Disallowed', url: 'https://example.invalid/track.mp3'},
        {id: 4, title: 'Disallowed script', url: 'javascript:alert(1)'}
    ]);
    const {root, media, nodes, buttons, context} = fixture;
    const node = name => nodes.get(`[data-player-${name}]`);
    check(root.dataset.playerIndex === '0' && media.paused && media.playCalls === 0 && media.controls, `${kind}: initial load uses native controls without autoplay`);
    check(node('title').textContent === firstTitle && node('text').textContent === '<b>First lyrics</b>' && node('title').innerHTML === undefined, `${kind}: titles and lyrics remain plain text`);
    vm.runInContext(source, context);
    check(media.loadCalls === 1, `${kind}: repeated initialization does not reset playback`);
    node('toggle').fire('click');
    await settle();
    check(media.playCalls === 1 && root.dataset.playerState === 'playing' && node('toggle').textContent === 'Pause', `${kind}: first click starts playback`);
    media.paused = true; media.ended = true; media.fire('ended');
    await settle();
    check(root.dataset.playerIndex === '1' && media.playCalls === 2 && !media.paused && node('title').textContent === 'Second item', `${kind}: ended automatically loads and plays the following item`);
    check(node('prev').disabled === false && node('next').disabled === true && buttons[1].getAttribute('aria-current') === 'true', `${kind}: queue and navigation reflect the active item`);
    check(kind === 'audio' ? media.children.length === 0 : media.children.length === 1 && media.children[0].src.endsWith('id=2'), `${kind}: subtitles are replaced for the current video only`);
    media.paused = true; media.ended = true; media.fire('ended');
    await settle();
    check(media.playCalls === 2 && root.dataset.playerState === 'finished' && node('status').textContent === 'Playlist finished.', `${kind}: queue stops at its final permitted item`);
    node('repeat').fire('click'); media.fire('ended');
    await settle();
    check(root.dataset.playerIndex === '0' && media.playCalls === 3 && node('repeat').getAttribute('aria-pressed') === 'true', `${kind}: repeat is explicit and wraps to the first item`);
    node('prev').fire('click');
    await settle();
    check(root.dataset.playerIndex === '1' && media.playCalls === 4, `${kind}: previous wraps when repeat is enabled`);
    buttons[0].fire('click');
    await settle();
    check(root.dataset.playerIndex === '0' && media.playCalls === 5, `${kind}: choosing a queue item starts that item`);
    node('toggle').fire('click');
    check(media.paused && root.dataset.playerState === 'paused', `${kind}: pause preserves the current item`);
    media.rejectPlayback = true; node('toggle').fire('click');
    await settle();
    check(root.dataset.playerState === 'paused' && node('status').textContent.includes('Press Play'), `${kind}: blocked playback produces an actionable retry state`);
    media.fire('error');
    check(root.dataset.playerState === 'error' && node('status').textContent.includes('Choose another'), `${kind}: media failures remain visible without silently skipping items`);
}

const empty = make('audio', []);
check(empty.nodes.get('[data-player-toggle]').disabled && empty.media.playCalls === 0, 'empty playlist has no active playback controls');
const malformed = make('video', '{broken');
check(malformed.nodes.get('[data-player-status]').textContent.includes('could not be loaded'), 'malformed queue produces a readable error');
console.log(`${assertions} player state and sequential-playback checks passed.`);
'''


def main():
    node = shutil.which('node')
    if not node:
        raise RuntimeError('Node.js is required for the player behavior checks.')
    source = Path(__file__).resolve().parents[1] / 'public/media-library/player.js'
    subprocess.run([node, '--input-type=module', '-e', SCRIPT, str(source)], check=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(f'FAIL: {error}', file=sys.stderr)
        sys.exit(1)
