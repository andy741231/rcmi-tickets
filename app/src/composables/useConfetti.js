/**
 * Lightweight celebration animations on a canvas overlay.
 * No external dependencies — pure canvas + requestAnimationFrame.
 *
 * Usage:
 *   import { useConfetti } from '../composables/useConfetti.js';
 *   const confetti = useConfetti();
 *   confetti.submitted({ subtext: 'Ticket #42' }); // cannons + balloons
 *   confetti.fireworks({ subtext: 'Ticket #42' }); // ticket completed
 *   confetti.burst({ subtext: 'Ticket #42' });     // ticket approved
 *
 * Every animation:
 *  - shows a small centered badge (animated check + headline + optional
 *    subtext) instead of bare text floating over the page;
 *  - uses time-based physics (normalized to 60 fps), so it looks the same
 *    on 60 Hz and 120 Hz displays;
 *  - respects prefers-reduced-motion (badge only, no particles);
 *  - cancels any celebration still running, so they never stack.
 * The badge is aria-hidden: callers already announce the outcome via toast.
 */

// UH brand palette (uh.edu/brand style guide). Red is the primary and
// stays dominant; accents (gold, teal, brick, mustard) are kept to roughly
// a quarter of the mix — the guide caps accents at 30% of a design.
const RED = '#C8102E';     // UH Red, PMS 186 C
const SLATE = '#54585A';   // secondary
const BRICK = '#960C22';   // accent
const GOLD = '#F6BE00';    // accent
const MUSTARD = '#D89B00'; // accent
const TEAL = '#00B388';    // accent

// Particles sit on a white page, so UH white is left out (it would be invisible).
const COLORS = [RED, RED, RED, RED, RED, BRICK, GOLD, TEAL];
const SPARKS = [RED, RED, RED, BRICK, GOLD, MUSTARD];

const pick = (arr) => arr[Math.floor(Math.random() * arr.length)];
const rand = (min, max) => min + Math.random() * (max - min);

let active = null; // { stop() } for the celebration currently on screen

function prefersReducedMotion() {
    return !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
}

function createCanvas() {
    const canvas = document.createElement('canvas');
    canvas.setAttribute('aria-hidden', 'true');
    canvas.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:99999;';
    document.body.appendChild(canvas);
    const ctx = canvas.getContext('2d');
    const size = { w: 0, h: 0, dpr: 1 };
    function resize() {
        size.dpr = Math.min(window.devicePixelRatio || 1, 2);
        size.w = window.innerWidth;
        size.h = window.innerHeight;
        canvas.width = size.w * size.dpr;
        canvas.height = size.h * size.dpr;
        canvas.style.width = size.w + 'px';
        canvas.style.height = size.h + 'px';
    }
    resize();
    window.addEventListener('resize', resize);
    return {
        ctx,
        size,
        destroy() {
            window.removeEventListener('resize', resize);
            canvas.remove();
        },
    };
}

/**
 * Centered badge: animated check mark, headline, optional subtext.
 * Pops in with a spring, holds, then lifts away before the animation ends.
 */
function createBadge({ text, subtext, accent, duration }) {
    const el = document.createElement('div');
    el.setAttribute('aria-hidden', 'true');
    el.style.cssText = [
        'position:fixed', 'left:50%', 'top:22%', 'z-index:100000',
        'pointer-events:none', 'display:flex', 'flex-direction:column',
        'align-items:center', 'gap:0.25rem', 'text-align:center',
        'padding:1.25rem 2rem 1.125rem', 'max-width:calc(100vw - 32px)',
        'background:rgba(255,255,255,0.96)', 'border-radius:20px',
        `border-top:4px solid ${RED}`,
        'box-shadow:0 24px 60px rgba(16,24,40,0.18),0 4px 12px rgba(16,24,40,0.08)',
        'opacity:0', 'transform:translate(-50%,-50%) scale(0.6)',
        'transition:opacity 0.3s ease,transform 0.55s cubic-bezier(0.34,1.56,0.64,1)',
        'will-change:transform,opacity',
    ].join(';');

    const ring = 2 * Math.PI * 22;
    el.innerHTML = `
        <svg width="56" height="56" viewBox="0 0 56 56" fill="none" style="display:block;margin-bottom:0.25rem">
            <circle cx="28" cy="28" r="22" stroke="${accent}" stroke-opacity="0.15" stroke-width="4"/>
            <circle cx="28" cy="28" r="22" stroke="${accent}" stroke-width="4" stroke-linecap="round"
                transform="rotate(-90 28 28)" stroke-dasharray="${ring}" stroke-dashoffset="${ring}"
                style="transition:stroke-dashoffset 0.6s cubic-bezier(0.65,0,0.35,1) 0.15s"/>
            <path d="M18 29l7 7 13-14" stroke="${accent}" stroke-width="4.5" stroke-linecap="round"
                stroke-linejoin="round" stroke-dasharray="32" stroke-dashoffset="32"
                style="transition:stroke-dashoffset 0.35s ease-out 0.65s"/>
        </svg>`;

    const title = document.createElement('div');
    title.textContent = text;
    title.style.cssText = [
        'font-family:"League Gothic","Arial Narrow",sans-serif',
        'font-size:clamp(2rem,6vw,3rem)', 'line-height:1', 'letter-spacing:0.03em',
        'text-transform:uppercase', `color:${RED}`, 'white-space:nowrap',
    ].join(';');
    el.appendChild(title);

    if (subtext) {
        const sub = document.createElement('div');
        sub.textContent = subtext;
        sub.style.cssText = `font-family:"Source Sans 3",system-ui,sans-serif;font-size:0.9375rem;font-weight:600;color:${SLATE}`;
        el.appendChild(sub);
    }

    document.body.appendChild(el);

    // Two frames so the initial styles commit before transitioning.
    requestAnimationFrame(() => requestAnimationFrame(() => {
        el.style.opacity = '1';
        el.style.transform = 'translate(-50%,-50%) scale(1)';
        el.querySelectorAll('[stroke-dashoffset]').forEach((n) => n.setAttribute('stroke-dashoffset', '0'));
    }));

    const outAt = Math.max(duration - 550, duration * 0.7);
    const outTimer = setTimeout(() => {
        el.style.transition = 'opacity 0.45s ease,transform 0.45s ease';
        el.style.opacity = '0';
        el.style.transform = 'translate(-50%,-62%) scale(0.96)';
    }, outAt);

    return {
        /** Final on-screen box (layout size, ignoring the pop-in scale). */
        rect() {
            const w = el.offsetWidth;
            const h = el.offsetHeight;
            const cx = window.innerWidth / 2;
            const cy = window.innerHeight * 0.22;
            return { left: cx - w / 2, right: cx + w / 2, top: cy - h / 2, bottom: cy + h / 2 };
        },
        remove() {
            clearTimeout(outTimer);
            el.remove();
        },
    };
}

/** Distance from a point to the nearest edge of a rect (0 when inside). */
function distToRect(x, y, r) {
    const dx = Math.max(r.left - x, 0, x - r.right);
    const dy = Math.max(r.top - y, 0, y - r.bottom);
    return Math.hypot(dx, dy);
}

/**
 * Shared driver: badge + canvas + time-based loop.
 * `frame(ctx, size, step, elapsed, progress)` draws one frame; `step` is
 * the elapsed time in 60 fps frames (≈1 at 60 Hz, ≈0.5 at 120 Hz).
 */
function run({ duration, badge, setup, frame }) {
    if (active) active.stop();

    const badgeEl = badge ? createBadge({ ...badge, duration }) : null;

    if (prefersReducedMotion()) {
        const t = setTimeout(stop, duration);
        function stop() {
            clearTimeout(t);
            if (badgeEl) badgeEl.remove();
            if (active === handle) active = null;
        }
        const handle = { stop };
        active = handle;
        return;
    }

    const { ctx, size, destroy } = createCanvas();
    const state = setup ? setup(size, badgeEl ? badgeEl.rect() : null) : {};
    const start = performance.now();
    let last = start;
    let rafId;

    function tick(now) {
        const elapsed = now - start;
        const progress = Math.min(1, elapsed / duration);
        // Clamp so a backgrounded tab doesn't teleport particles.
        const step = Math.min((now - last) / (1000 / 60), 3);
        last = now;

        ctx.setTransform(size.dpr, 0, 0, size.dpr, 0, 0);
        ctx.clearRect(0, 0, size.w, size.h);
        frame(ctx, size, step, elapsed, progress, state);

        if (progress < 1) rafId = requestAnimationFrame(tick);
        else stop();
    }

    function stop() {
        cancelAnimationFrame(rafId);
        if (state.timers) state.timers.forEach(clearTimeout);
        destroy();
        if (badgeEl) badgeEl.remove();
        if (active === handle) active = null;
    }

    const handle = { stop };
    active = handle;
    rafId = requestAnimationFrame(tick);
}

/** Paper confetti: flat rectangles that flutter (scale on one axis). */
function makePiece(x, y, vx, vy, opts = {}) {
    return {
        x, y, vx, vy,
        gravity: opts.gravity ?? rand(0.16, 0.24),
        drag: opts.drag ?? 0.985,
        size: rand(7, 12),
        color: opts.color ?? pick(COLORS),
        shape: Math.random() < (opts.ribbons ?? 0) ? 'ribbon' : 'rect',
        rotation: rand(0, Math.PI * 2),
        spin: rand(-0.25, 0.25),
        flutter: rand(0, Math.PI * 2),
        flutterSpeed: rand(0.12, 0.22),
        sway: rand(0.4, 1.2),
    };
}

function stepPiece(p, step) {
    const drag = Math.pow(p.drag, step);
    p.vx *= drag;
    p.vy = p.vy * drag + p.gravity * step;
    // Terminal velocity — paper drifts, it doesn't plummet.
    if (p.vy > 4.5) p.vy = 4.5;
    p.flutter += p.flutterSpeed * step;
    p.x += (p.vx + Math.sin(p.flutter) * p.sway * 0.6) * step;
    p.y += p.vy * step;
    p.rotation += p.spin * step;
}

function drawPiece(ctx, p, alpha, dpr) {
    const cos = Math.cos(p.rotation);
    const sin = Math.sin(p.rotation);
    ctx.setTransform(cos * dpr, sin * dpr, -sin * dpr, cos * dpr, p.x * dpr, p.y * dpr);
    ctx.globalAlpha = alpha;
    if (p.shape === 'ribbon') {
        ctx.strokeStyle = p.color;
        ctx.lineWidth = 2.5;
        ctx.lineCap = 'round';
        ctx.beginPath();
        const len = p.size * 2;
        for (let x = -len / 2; x <= len / 2; x += 2) {
            const y = Math.sin(x * 0.45 + p.flutter) * 3.5;
            if (x === -len / 2) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        }
        ctx.stroke();
    } else {
        ctx.fillStyle = p.color;
        const squash = Math.abs(Math.cos(p.flutter)) * 0.85 + 0.15;
        ctx.fillRect(-p.size / 2, (-p.size / 4) * squash, p.size, (p.size / 2) * squash);
    }
}

function fadeOut(progress, from) {
    return progress > from ? Math.max(0, 1 - (progress - from) / (1 - from)) : 1;
}

export function useConfetti() {
    /**
     * Ticket approved: a confetti pop from behind the badge that drifts down.
     */
    function burst(opts = {}) {
        const duration = opts.duration ?? 2600;
        const count = opts.count ?? 140;
        run({
            duration,
            badge: opts.text === '' ? null : {
                text: opts.text ?? 'Go Coogs!',
                subtext: opts.subtext,
                accent: opts.textColor ?? RED,
            },
            setup(size) {
                const pieces = [];
                const cx = size.w / 2;
                const cy = size.h * 0.22;
                for (let i = 0; i < count; i++) {
                    // Mostly upward fan so pieces rise past the badge then fall.
                    const angle = -Math.PI / 2 + rand(-1.25, 1.25);
                    const speed = rand(6, 14);
                    pieces.push(makePiece(cx + rand(-40, 40), cy + rand(-10, 10),
                        Math.cos(angle) * speed, Math.sin(angle) * speed,
                        { drag: 0.96, ribbons: 0.12 }));
                }
                return { pieces };
            },
            frame(ctx, size, step, elapsed, progress, { pieces }) {
                const alpha = fadeOut(progress, 0.7);
                for (const p of pieces) {
                    stepPiece(p, step);
                    drawPiece(ctx, p, alpha, size.dpr);
                }
            },
        });
    }

    /**
     * Ticket completed: rockets climb with a tapering trail, then burst into
     * streaking sparks (drawn as short lines, which read as fireworks on a
     * light page where round dots look like confetti).
     */
    function fireworks(opts = {}) {
        const duration = opts.duration ?? 4000;
        const rocketCount = opts.rockets ?? 7;
        run({
            duration,
            badge: opts.text === '' ? null : {
                text: opts.text ?? 'Ticket completed!',
                subtext: opts.subtext,
                accent: opts.textColor ?? TEAL,
            },
            setup(size, badgeRect) {
                // Bursts spread ~150px before fading, so aim each rocket at a
                // point at least that far from the badge — the card sits above
                // the canvas and would otherwise hide the pop. Sample candidate
                // spots and keep the first clear one (or the clearest found);
                // on narrow screens this naturally moves bursts below the card.
                const CLEAR = 160;
                function burstPoint(i) {
                    let best = null;
                    let bestD = -1;
                    for (let t = 0; t < 40; t++) {
                        // Alternate halves so bursts frame the card on wide screens.
                        const half = i % 2 === 0 ? rand(0.05, 0.5) : rand(0.5, 0.95);
                        const x = size.w * half;
                        const y = size.h * rand(0.12, 0.62);
                        const d = badgeRect ? distToRect(x, y, badgeRect) : Infinity;
                        if (d >= CLEAR) return { x, y };
                        if (d > bestD) { bestD = d; best = { x, y }; }
                    }
                    return best;
                }

                const rockets = [];
                for (let i = 0; i < rocketCount; i++) {
                    const target = burstPoint(i);
                    rockets.push({
                        launchAt: (i / rocketCount) * duration * 0.55 + rand(0, 180),
                        x: target.x,
                        y: size.h + 10,
                        targetY: target.y,
                        vx: 0,
                        color: pick(SPARKS),
                        launched: false,
                        exploded: false,
                        trail: [],
                        sparks: [],
                    });
                }
                return { rockets };
            },
            frame(ctx, size, step, elapsed, progress, { rockets }) {
                const dpr = size.dpr;
                ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
                ctx.lineCap = 'round';
                const globalFade = fadeOut(progress, 0.8);

                for (const r of rockets) {
                    if (elapsed < r.launchAt) continue;

                    if (!r.exploded) {
                        if (!r.launched) {
                            r.launched = true;
                            // Initial speed that just reaches targetY under 0.12 decel.
                            r.vy = -Math.sqrt(2 * 0.12 * (r.y - r.targetY));
                        }
                        r.vy += 0.12 * step;
                        r.x += r.vx * step;
                        r.y += r.vy * step;
                        r.trail.push({ x: r.x, y: r.y });
                        if (r.trail.length > 10) r.trail.shift();

                        for (let i = 1; i < r.trail.length; i++) {
                            const a = r.trail[i - 1];
                            const b = r.trail[i];
                            ctx.globalAlpha = (i / r.trail.length) * 0.9;
                            ctx.strokeStyle = r.color;
                            ctx.lineWidth = 1 + (i / r.trail.length) * 2;
                            ctx.beginPath();
                            ctx.moveTo(a.x, a.y);
                            ctx.lineTo(b.x, b.y);
                            ctx.stroke();
                        }

                        if (r.vy >= -0.5 || r.y <= r.targetY) {
                            r.exploded = true;
                            r.explodedAt = elapsed;
                            const n = 90 + Math.floor(Math.random() * 30);
                            const power = rand(7, 9.5);
                            const second = pick(SPARKS);
                            for (let i = 0; i < n; i++) {
                                const angle = (Math.PI * 2 * i) / n + rand(-0.05, 0.05);
                                const speed = power * rand(0.45, 1);
                                r.sparks.push({
                                    x: r.x, y: r.y, px: r.x, py: r.y,
                                    vx: Math.cos(angle) * speed,
                                    vy: Math.sin(angle) * speed,
                                    color: Math.random() < 0.75 ? r.color : second,
                                    life: rand(1100, 1700),
                                    width: rand(2.4, 3.6),
                                });
                            }
                        }
                    }

                    if (r.exploded) {
                        const age = elapsed - r.explodedAt;
                        // Brief flash at the burst center.
                        if (age < 160) {
                            ctx.globalAlpha = (1 - age / 160) * 0.5 * globalFade;
                            ctx.fillStyle = r.color;
                            ctx.beginPath();
                            ctx.arc(r.x, r.y, 14 + age * 0.2, 0, Math.PI * 2);
                            ctx.fill();
                        }
                        for (const s of r.sparks) {
                            const life = 1 - age / s.life;
                            if (life <= 0) continue;
                            s.px = s.x;
                            s.py = s.y;
                            const drag = Math.pow(0.955, step);
                            s.vx *= drag;
                            s.vy = s.vy * drag + 0.045 * step;
                            s.x += s.vx * step;
                            s.y += s.vy * step;
                            // Streak from a point a few frames back along the velocity.
                            const tail = 4.5;
                            ctx.globalAlpha = Math.min(1, life * 1.4) * globalFade;
                            ctx.strokeStyle = s.color;
                            ctx.lineWidth = s.width * (0.5 + life * 0.5);
                            ctx.beginPath();
                            ctx.moveTo(s.x - s.vx * tail, s.y - s.vy * tail);
                            ctx.lineTo(s.x, s.y);
                            ctx.stroke();
                        }
                    }
                }
            },
        });
    }

    /**
     * Ticket submitted: confetti cannons fire from both bottom corners while
     * balloons float up, with a few curly ribbons mixed in.
     */
    function submitted(opts = {}) {
        const duration = opts.duration ?? 4200;
        // Red-led (UH Red + Brick), with gold and teal as the accent pair.
        const BALLOONS = [RED, BRICK, RED, GOLD, RED, RED, TEAL, BRICK, RED];
        run({
            duration,
            badge: opts.text === '' ? null : {
                text: opts.text ?? 'Ticket submitted!',
                subtext: opts.subtext,
                accent: opts.textColor ?? RED,
            },
            setup(size) {
                const { w, h } = size;
                const pieces = [];
                const fire = (originX, dir, count) => {
                    for (let i = 0; i < count; i++) {
                        const angle = -Math.PI / 2 + dir * rand(0.12, 0.6);
                        const speed = rand(14, 24) * Math.min(1, h / 800 + 0.25);
                        pieces.push(makePiece(originX, h + 10,
                            Math.cos(angle) * speed, Math.sin(angle) * speed,
                            { drag: 0.975, ribbons: 0.18 }));
                    }
                };
                fire(0, 1, 60);
                fire(w, -1, 60);
                const timers = [setTimeout(() => { fire(0, 1, 40); fire(w, -1, 40); }, 420)];

                const balloons = [];
                const balloonCount = w < 640 ? 6 : BALLOONS.length;
                for (let i = 0; i < balloonCount; i++) {
                    balloons.push({
                        x: w * (0.06 + (i / (balloonCount - 1)) * 0.88) + rand(-20, 20),
                        y: h + 60 + rand(0, 140),
                        delay: rand(0, 700),
                        // Rise speed scales with height so balloons clear the viewport.
                        vy: (h / 240) * rand(0.9, 1.3),
                        r: rand(24, 36),
                        color: BALLOONS[i % BALLOONS.length],
                        phase: rand(0, Math.PI * 2),
                        sway: rand(0.02, 0.04),
                    });
                }
                return { pieces, balloons, timers };
            },
            frame(ctx, size, step, elapsed, progress, { pieces, balloons }) {
                const alpha = fadeOut(progress, 0.78);
                const dpr = size.dpr;

                for (const b of balloons) {
                    if (elapsed < b.delay) continue;
                    // Ease in: balloons accelerate gently off the bottom edge.
                    const ease = Math.min(1, (elapsed - b.delay) / 600);
                    b.y -= b.vy * ease * step;
                    b.phase += b.sway * step;
                    drawBalloon(ctx, b, alpha, dpr);
                }

                for (const p of pieces) {
                    stepPiece(p, step);
                    drawPiece(ctx, p, alpha, dpr);
                }
            },
        });
    }

    return { burst, fireworks, submitted };
}

function drawBalloon(ctx, b, alpha, dpr) {
    const rx = b.r * 0.86;
    const ry = b.r * 1.05;
    const swayX = Math.sin(b.phase) * 14;
    const tilt = Math.sin(b.phase) * 0.08;
    const cos = Math.cos(tilt);
    const sin = Math.sin(tilt);
    ctx.setTransform(cos * dpr, sin * dpr, -sin * dpr, cos * dpr, (b.x + swayX) * dpr, b.y * dpr);
    ctx.globalAlpha = alpha;

    // String
    ctx.strokeStyle = 'rgba(107,114,128,0.6)';
    ctx.lineWidth = 1.2;
    ctx.beginPath();
    ctx.moveTo(0, ry + 6);
    ctx.bezierCurveTo(-8, ry + 30, 8, ry + 55, -swayX * 0.4, ry + 95);
    ctx.stroke();

    // Body (slightly egg-shaped: wider at the top)
    ctx.fillStyle = b.color;
    ctx.beginPath();
    ctx.moveTo(0, ry);
    ctx.bezierCurveTo(rx * 1.15, ry * 0.55, rx * 1.1, -ry, 0, -ry);
    ctx.bezierCurveTo(-rx * 1.1, -ry, -rx * 1.15, ry * 0.55, 0, ry);
    ctx.fill();
    ctx.strokeStyle = 'rgba(0,0,0,0.1)';
    ctx.lineWidth = 1;
    ctx.stroke();

    // Highlight
    ctx.globalAlpha = alpha * 0.45;
    ctx.fillStyle = '#ffffff';
    ctx.beginPath();
    ctx.ellipse(-rx * 0.38, -ry * 0.42, rx * 0.18, ry * 0.3, -0.5, 0, Math.PI * 2);
    ctx.fill();

    // Knot
    ctx.globalAlpha = alpha;
    ctx.fillStyle = b.color;
    ctx.beginPath();
    ctx.moveTo(0, ry - 2);
    ctx.lineTo(-5, ry + 6);
    ctx.lineTo(5, ry + 6);
    ctx.closePath();
    ctx.fill();
}
