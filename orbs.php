<canvas id="particles-bg" style="position:fixed;top:0;left:0;width:100%;height:100%;z-index:0;pointer-events:none;"></canvas>
<div class="page-orbs">
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
    <div class="orb orb-4"></div>
    <div class="orb orb-5"></div>
</div>
<script>
    (function() {
        var c = document.getElementById('particles-bg'),
            ctx = c.getContext('2d');
        c.width = window.innerWidth;
        c.height = window.innerHeight;
        var pts = [];
        for (var i = 0; i < 50; i++) pts.push({
            x: Math.random() * c.width,
            y: Math.random() * c.height,
            vx: (Math.random() - 0.5) * 0.2,
            vy: (Math.random() - 0.5) * 0.2,
            r: Math.random() * 1.5 + 0.5,
            o: Math.random() * 0.25 + 0.05
        });

        function draw() {
            ctx.clearRect(0, 0, c.width, c.height);
            for (var i = 0; i < pts.length; i++) {
                var p = pts[i];
                p.x += p.vx;
                p.y += p.vy;
                if (p.x < 0) p.x = c.width;
                if (p.x > c.width) p.x = 0;
                if (p.y < 0) p.y = c.height;
                if (p.y > c.height) p.y = 0;
                ctx.beginPath();
                ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(107,141,181,' + p.o + ')';
                ctx.fill();
                for (var j = i + 1; j < pts.length; j++) {
                    var q = pts[j],
                        dx = p.x - q.x,
                        dy = p.y - q.y,
                        d = Math.sqrt(dx * dx + dy * dy);
                    if (d < 150) {
                        ctx.beginPath();
                        ctx.moveTo(p.x, p.y);
                        ctx.lineTo(q.x, q.y);
                        ctx.strokeStyle = 'rgba(107,141,181,' + (0.04 * (1 - d / 150)) + ')';
                        ctx.stroke();
                    }
                }
            }
            requestAnimationFrame(draw);
        }
        draw();
        window.addEventListener('resize', function() {
            c.width = window.innerWidth;
            c.height = window.innerHeight;
        });
    })();
</script>