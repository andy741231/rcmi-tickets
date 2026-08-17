/**
 * Lightweight celebration animations on a canvas overlay.
 * No external dependencies — pure canvas + requestAnimationFrame.
 *
 * Usage:
 *   import { useConfetti } from '../composables/useConfetti.js';
 *   const confetti = useConfetti();
 *   confetti.burst();          // quick 1.8s confetti shower
 *   confetti.fireworks();      // multi-rocket firework show (~4s)
 *   confetti.burst({ count: 200, duration: 3000 });
 */

const COLORS = [
    '#c8102e', // RCMI red
    '#00B388', // RCMI teal
    '#a66b00', // RCMI gold
    '#fbbf24', // amber
    '#34d399', // emerald
    '#60a5fa', // blue
    '#f472b6', // pink
    '#ffffff', // white
];

function createCanvas() {
    const canvas = document.createElement('canvas');
    canvas.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:99999;';
    document.body.appendChild(canvas);
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    function resize() {
        canvas.width = window.innerWidth * dpr;
        canvas.height = window.innerHeight * dpr;
        canvas.style.width = window.innerWidth + 'px';
        canvas.style.height = window.innerHeight + 'px';
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }
    resize();
    window.addEventListener('resize', resize);
    return { canvas, ctx, resize, dpr };
}

/**
 * Create a centered text overlay that fades in/out with the animation.
 * @param {string} text
 * @param {string} color — CSS color
 * @param {number} duration — total animation duration (ms), used for fade timing
 * @returns {{ el: HTMLElement, remove: () => void }}
 */
function createTextOverlay(text, color, duration) {
    const el = document.createElement('div');
    el.textContent = text;
    el.style.cssText = [
        'position:fixed',
        'top:18%',
        'left:50%',
        `transform:translate(-50%,-50%) scale(0.5)`,
        'z-index:100000',
        'pointer-events:none',
        `color:${color}`,
        'font-family:"League Gothic",Arial Narrow,sans-serif',
        'font-size:clamp(2.5rem,9vw,5rem)',
        'font-weight:700',
        'letter-spacing:0.04em',
        'text-transform:uppercase',
        'text-shadow:0 4px 24px rgba(0,0,0,0.35),0 2px 4px rgba(0,0,0,0.2)',
        'white-space:nowrap',
        'opacity:0',
        'transition:opacity 0.4s ease,transform 0.5s cubic-bezier(0.34,1.56,0.64,1)',
    ].join(';');
    document.body.appendChild(el);

    // Fade in + pop scale after a tick
    requestAnimationFrame(() => {
        el.style.opacity = '1';
        el.style.transform = 'translate(-50%,-50%) scale(1)';
    });

    // Fade out near the end of the animation
    const fadeOutAt = Math.max(duration - 600, duration * 0.7);
    const fadeTimer = setTimeout(() => {
        el.style.opacity = '0';
        el.style.transform = 'translate(-50%,-50%) scale(1.15)';
    }, fadeOutAt);

    const removeTimer = setTimeout(() => el.remove(), duration + 100);

    return {
        el,
        remove() {
            clearTimeout(fadeTimer);
            clearTimeout(removeTimer);
            el.remove();
        },
    };
}

export function useConfetti() {
    function burst(opts = {}) {
        const count = opts.count ?? 150;
        const duration = opts.duration ?? 1800;
        const text = opts.text ?? 'Go Coogs!';
        const textColor = opts.textColor ?? '#c8102e';

        const { canvas, ctx, resize } = createCanvas();
        const textOverlay = text ? createTextOverlay(text, textColor, duration) : null;
        const particles = [];
        const w = window.innerWidth;
        const h = window.innerHeight;

        for (let i = 0; i < count; i++) {
            const angle = (Math.PI * 2 * i) / count + Math.random() * 0.5;
            const speed = 3 + Math.random() * 7;
            particles.push({
                x: w / 2 + (Math.random() - 0.5) * 100,
                y: h / 3 + (Math.random() - 0.5) * 50,
                vx: Math.cos(angle) * speed * (0.5 + Math.random()),
                vy: Math.sin(angle) * speed - Math.random() * 8 - 4,
                gravity: 0.15 + Math.random() * 0.1,
                size: 6 + Math.random() * 8,
                color: COLORS[Math.floor(Math.random() * COLORS.length)],
                rotation: Math.random() * Math.PI * 2,
                rotationSpeed: (Math.random() - 0.5) * 0.3,
                shape: Math.random() > 0.5 ? 'rect' : 'circle',
                opacity: 1,
                wobble: Math.random() * Math.PI * 2,
                wobbleSpeed: 0.1 + Math.random() * 0.1,
            });
        }

        const start = performance.now();
        let rafId;

        function animate(now) {
            const elapsed = now - start;
            const progress = elapsed / duration;

            ctx.clearRect(0, 0, w, h);

            for (const p of particles) {
                p.vy += p.gravity;
                p.x += p.vx;
                p.y += p.vy;
                p.rotation += p.rotationSpeed;
                p.wobble += p.wobbleSpeed;
                p.vx *= 0.99;
                p.opacity = progress > 0.7 ? Math.max(0, 1 - (progress - 0.7) / 0.3) : 1;

                const wobbleX = Math.sin(p.wobble) * 2;

                ctx.save();
                ctx.globalAlpha = p.opacity;
                ctx.translate(p.x + wobbleX, p.y);
                ctx.rotate(p.rotation);
                ctx.fillStyle = p.color;

                if (p.shape === 'rect') {
                    ctx.fillRect(-p.size / 2, -p.size / 4, p.size, p.size / 2);
                } else {
                    ctx.beginPath();
                    ctx.arc(0, 0, p.size / 2, 0, Math.PI * 2);
                    ctx.fill();
                }

                ctx.restore();
            }

            if (progress < 1) {
                rafId = requestAnimationFrame(animate);
            } else {
                cleanup();
            }
        }

        function cleanup() {
            cancelAnimationFrame(rafId);
            window.removeEventListener('resize', resize);
            canvas.remove();
            if (textOverlay) textOverlay.remove();
        }

        rafId = requestAnimationFrame(animate);
    }

    /**
     * Firework animation: rockets launch from the bottom, explode into
     * colorful particle bursts at varying heights, with gravity + fade.
     *
     * @param {object} opts
     * @param {number} opts.rockets   — number of rockets to launch (default 6)
     * @param {number} opts.duration  — total show duration in ms (default 4000)
     */
    function fireworks(opts = {}) {
        const rocketCount = opts.rockets ?? 6;
        const duration = opts.duration ?? 4000;
        const text = opts.text ?? 'Amazing job!';
        const textColor = opts.textColor ?? '#00B388';

        const { canvas, ctx, resize } = createCanvas();
        const textOverlay = text ? createTextOverlay(text, textColor, duration) : null;
        const w = window.innerWidth;
        const h = window.innerHeight;

        // Schedule rocket launches spread across the first 60% of duration
        const rockets = [];
        for (let i = 0; i < rocketCount; i++) {
            rockets.push({
                launchAt: (i / rocketCount) * duration * 0.6 + Math.random() * 200,
                x: w * (0.15 + Math.random() * 0.7),
                targetY: h * (0.15 + Math.random() * 0.25),
                color: COLORS[Math.floor(Math.random() * COLORS.length)],
                exploded: false,
                y: h,
                vy: 0,
                trail: [],
                sparks: [],
            });
        }

        const start = performance.now();
        let rafId;

        function explode(rocket) {
            const sparkCount = 60 + Math.floor(Math.random() * 40);
            for (let i = 0; i < sparkCount; i++) {
                const angle = (Math.PI * 2 * i) / sparkCount + Math.random() * 0.2;
                const speed = 2 + Math.random() * 5;
                rocket.sparks.push({
                    x: rocket.x,
                    y: rocket.y,
                    vx: Math.cos(angle) * speed,
                    vy: Math.sin(angle) * speed,
                    gravity: 0.06 + Math.random() * 0.04,
                    size: 2 + Math.random() * 3,
                    color: Math.random() > 0.3 ? rocket.color : COLORS[Math.floor(Math.random() * COLORS.length)],
                    opacity: 1,
                    twinkle: Math.random() * Math.PI * 2,
                });
            }
        }

        function animate(now) {
            const elapsed = now - start;
            const progress = elapsed / duration;

            ctx.clearRect(0, 0, w, h);

            for (const r of rockets) {
                if (elapsed < r.launchAt) continue;

                // Rising rocket (not yet exploded)
                if (!r.exploded) {
                    if (r.vy === 0) r.vy = -(h - r.targetY) / 30; // initial velocity to reach target in ~30 frames
                    r.y += r.vy;
                    r.vy += 0.08; // decelerate

                    // Trail
                    r.trail.push({ x: r.x, y: r.y, opacity: 1 });
                    if (r.trail.length > 12) r.trail.shift();

                    // Draw trail
                    for (let i = 0; i < r.trail.length; i++) {
                        const t = r.trail[i];
                        t.opacity = (i / r.trail.length) * 0.8;
                        ctx.save();
                        ctx.globalAlpha = t.opacity;
                        ctx.fillStyle = r.color;
                        ctx.beginPath();
                        ctx.arc(t.x, t.y, 2, 0, Math.PI * 2);
                        ctx.fill();
                        ctx.restore();
                    }

                    // Draw rocket head
                    ctx.save();
                    ctx.globalAlpha = 1;
                    ctx.fillStyle = '#ffffff';
                    ctx.beginPath();
                    ctx.arc(r.x, r.y, 3, 0, Math.PI * 2);
                    ctx.fill();
                    ctx.restore();

                    // Explode when reaching target or slowing down
                    if (r.y <= r.targetY || r.vy >= 0) {
                        r.exploded = true;
                        explode(r);
                    }
                }

                // Sparks from explosion
                for (const s of r.sparks) {
                    s.vy += s.gravity;
                    s.x += s.vx;
                    s.y += s.vy;
                    s.vx *= 0.98;
                    s.twinkle += 0.2;
                    s.opacity = progress > 0.6 ? Math.max(0, 1 - (progress - 0.6) / 0.4) : 1;

                    const flicker = 0.7 + Math.sin(s.twinkle) * 0.3;

                    ctx.save();
                    ctx.globalAlpha = s.opacity * flicker;
                    ctx.fillStyle = s.color;
                    ctx.beginPath();
                    ctx.arc(s.x, s.y, s.size, 0, Math.PI * 2);
                    ctx.fill();

                    // Glow halo
                    ctx.globalAlpha = s.opacity * flicker * 0.3;
                    ctx.beginPath();
                    ctx.arc(s.x, s.y, s.size * 2.5, 0, Math.PI * 2);
                    ctx.fill();
                    ctx.restore();
                }
            }

            if (progress < 1) {
                rafId = requestAnimationFrame(animate);
            } else {
                cleanup();
            }
        }

        function cleanup() {
            cancelAnimationFrame(rafId);
            window.removeEventListener('resize', resize);
            canvas.remove();
            if (textOverlay) textOverlay.remove();
        }

        rafId = requestAnimationFrame(animate);
    }

    return { burst, fireworks };
}
