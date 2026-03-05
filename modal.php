<div class="modal-overlay" id="modalOverlay">
    <div class="modal-box" id="modalBox">
        <div class="modal-icon" id="modalIcon"></div>
        <div class="modal-title" id="modalTitle"></div>
        <div class="modal-message" id="modalMessage"></div>
        <div class="modal-actions" id="modalActions"></div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.2/dist/confetti.browser.min.js"></script>
<script>
    function showModal(type, title, message, onConfirm, onCancel) {
        var ov = document.getElementById('modalOverlay'),
            bx = document.getElementById('modalBox'),
            ic = document.getElementById('modalIcon'),
            ti = document.getElementById('modalTitle'),
            mg = document.getElementById('modalMessage'),
            ac = document.getElementById('modalActions');
        ic.className = 'modal-icon';
        if (type === 'danger') {
            ic.innerHTML = '<i class="fas fa-trash-can"></i>';
            ic.classList.add('danger');
        } else if (type === 'warning') {
            ic.innerHTML = '<i class="fas fa-triangle-exclamation"></i>';
            ic.classList.add('warning');
        } else {
            ic.innerHTML = '<i class="fas fa-circle-question"></i>';
            ic.classList.add('confirm');
        }
        ti.textContent = title;
        mg.textContent = message;
        ac.innerHTML = '';
        var cb = document.createElement('button');
        cb.className = 'btn btn-secondary';
        cb.innerHTML = '<i class="fas fa-xmark"></i> Cancel';
        cb.onclick = function() {
            closeModal();
            if (onCancel) onCancel();
        };
        var okb = document.createElement('button');
        if (type === 'danger') {
            okb.className = 'btn btn-danger';
            okb.innerHTML = '<i class="fas fa-trash-can"></i> Delete';
        } else if (type === 'warning') {
            okb.className = 'btn btn-warning';
            okb.innerHTML = '<i class="fas fa-check"></i> Proceed';
        } else {
            okb.className = 'btn btn-primary';
            okb.innerHTML = '<i class="fas fa-check"></i> Confirm';
        }
        okb.onclick = function() {
            closeModal();
            if (onConfirm) onConfirm();
        };
        ac.appendChild(cb);
        ac.appendChild(okb);
        bx.classList.remove('closing');
        ov.classList.add('active');
    }

    function closeModal() {
        var ov = document.getElementById('modalOverlay'),
            bx = document.getElementById('modalBox');
        bx.classList.add('closing');
        setTimeout(function() {
            ov.classList.remove('active');
            bx.classList.remove('closing');
        }, 250);
    }

    function confirmSubmit(fid, type, title, msg) {
        showModal(type, title, msg, function() {
            var f = document.getElementById(fid);
            if (f.requestSubmit) {
                f.requestSubmit();
            } else {
                f.submit();
            }
        });
        return false;
    }
    document.addEventListener('DOMContentLoaded', function() {
        var ov = document.getElementById('modalOverlay');
        if (ov) ov.addEventListener('click', function(e) {
            if (e.target === ov) closeModal();
        });
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            var ov = document.getElementById('modalOverlay');
            if (ov && ov.classList.contains('active')) closeModal();
        }
    });

    // Confetti on success messages
    document.addEventListener('DOMContentLoaded', function() {
        if (document.querySelector('.msg-success')) {
            confetti({
                particleCount: 120,
                spread: 80,
                origin: {
                    y: 0.3
                },
                colors: ['#6B8DB5', '#68A87A', '#C1A0D8', '#FFD9A0', '#FADADD']
            });
            setTimeout(function() {
                confetti({
                    particleCount: 60,
                    spread: 100,
                    origin: {
                        y: 0.4
                    }
                });
            }, 300);
        }
    });

    // Scroll reveal
    document.addEventListener('DOMContentLoaded', function() {
        var els = document.querySelectorAll('.card,.filter-section,.stat-card');
        var obs = new IntersectionObserver(function(entries) {
            entries.forEach(function(e) {
                if (e.isIntersecting) {
                    e.target.classList.add('revealed');
                    obs.unobserve(e.target);
                }
            });
        }, {
            threshold: 0.1,
            rootMargin: '0px 0px -40px 0px'
        });
        els.forEach(function(el) {
            el.classList.add('reveal-on-scroll');
            obs.observe(el);
        });
    });

    // Animated counters
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.stat-value[data-count]').forEach(function(el) {
            var target = parseInt(el.getAttribute('data-count')),
                dur = 1200,
                start = 0,
                startTime = null;

            function step(ts) {
                if (!startTime) startTime = ts;
                var p = Math.min((ts - startTime) / dur, 1);
                el.textContent = Math.floor(p * target);
                if (p < 1) requestAnimationFrame(step);
                else el.textContent = target;
            }
            requestAnimationFrame(step);
        });
    });

    // Magnetic buttons
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.btn-magnetic').forEach(function(btn) {
            btn.addEventListener('mousemove', function(e) {
                var r = btn.getBoundingClientRect(),
                    x = e.clientX - r.left - r.width / 2,
                    y = e.clientY - r.top - r.height / 2;
                btn.style.transform = 'translate(' + x * 0.15 + 'px,' + y * 0.15 + 'px)';
            });
            btn.addEventListener('mouseleave', function() {
                btn.style.transform = 'translate(0,0)';
            });
        });
    });

    // Cursor glow
    document.addEventListener('DOMContentLoaded', function() {
        var glow = document.createElement('div');
        glow.className = 'cursor-glow';
        document.body.appendChild(glow);
        document.addEventListener('mousemove', function(e) {
            glow.style.left = e.clientX + 'px';
            glow.style.top = e.clientY + 'px';
        });
    });
</script>