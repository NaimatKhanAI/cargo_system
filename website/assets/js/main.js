// ============================================
// Ilyas Cargo Services - Main JavaScript
// ============================================

document.addEventListener('DOMContentLoaded', function () {

    // --- Mobile Navigation Toggle ---
    const navToggle = document.querySelector('.nav-toggle');
    const navLinks = document.querySelector('.nav-links');
    const navPhone = document.querySelector('.nav-phone');

    if (navToggle) {
        navToggle.addEventListener('click', function () {
            this.classList.toggle('active');
            navLinks.classList.toggle('mobile-open');
            if (navPhone) navPhone.classList.toggle('mobile-open');
        });

        // Close mobile menu when clicking a link
        navLinks.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                navToggle.classList.remove('active');
                navLinks.classList.remove('mobile-open');
                if (navPhone) navPhone.classList.remove('mobile-open');
            });
        });
    }

    // --- Role Toggle (Join Us Form) ---
    const roleButtons = document.querySelectorAll('.role-toggle button');
    const truckerForm = document.getElementById('trucker-form');
    const companyForm = document.getElementById('company-form');
    const roleInput = document.getElementById('selected-role');

    roleButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            roleButtons.forEach(function (b) { b.classList.remove('active'); });
            this.classList.add('active');

            var role = this.getAttribute('data-role');
            if (roleInput) roleInput.value = role;

            if (role === 'trucker') {
                if (truckerForm) truckerForm.style.display = 'block';
                if (companyForm) companyForm.style.display = 'none';
            } else {
                if (truckerForm) truckerForm.style.display = 'none';
                if (companyForm) companyForm.style.display = 'block';
            }
        });
    });

    // --- Animate Stats on Scroll ---
    var statNumbers = document.querySelectorAll('.stat-number');
    var animated = false;

    function animateStats() {
        if (animated || statNumbers.length === 0) return;
        var firstStat = statNumbers[0];
        var rect = firstStat.getBoundingClientRect();
        if (rect.top < window.innerHeight - 50) {
            animated = true;
            statNumbers.forEach(function (el) {
                var target = el.getAttribute('data-target');
                if (!target) return;
                var num = parseInt(target);
                var suffix = el.getAttribute('data-suffix') || '';
                var duration = 1500;
                var start = 0;
                var startTime = null;

                function step(timestamp) {
                    if (!startTime) startTime = timestamp;
                    var progress = Math.min((timestamp - startTime) / duration, 1);
                    var eased = 1 - Math.pow(1 - progress, 3);
                    el.textContent = Math.floor(eased * num) + suffix;
                    if (progress < 1) requestAnimationFrame(step);
                }
                requestAnimationFrame(step);
            });
        }
    }

    window.addEventListener('scroll', animateStats);
    animateStats();

    // --- Smooth scroll for anchor links ---
    document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
        anchor.addEventListener('click', function (e) {
            var target = document.querySelector(this.getAttribute('href'));
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    // --- Google Sheets Logger ---
    // REPLACE THIS URL with your Google Apps Script deployment URL
    var GOOGLE_SHEET_URL = 'https://script.google.com/macros/s/AKfycbxn1F0Ek5PrawJJjiCJmsotbkqx2opAD-lOZ6PLu7wPm6iwgyqy4kx13gyvox-hvvqI/exec';

    function logToGoogleSheet(formData, formType) {
        if (GOOGLE_SHEET_URL === 'YOUR_GOOGLE_SCRIPT_URL') return;
        var data = {};
        formData.forEach(function (value, key) {
            if (data[key]) {
                // Handle multiple values (checkboxes)
                if (Array.isArray(data[key])) {
                    data[key].push(value);
                } else {
                    data[key] = [data[key], value];
                }
            } else {
                data[key] = value;
            }
        });
        data._formType = formType;

        fetch(GOOGLE_SHEET_URL, {
            method: 'POST',
            mode: 'no-cors',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        }).catch(function () {
            // Silent fail - email delivery via formsubmit is the primary method
        });
    }

    // --- Form Submission Handler ---
    var forms = document.querySelectorAll('form[data-form]');
    forms.forEach(function (form) {
        form.addEventListener('submit', function () {
            var submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.textContent = 'Sending...';
                submitBtn.disabled = true;
            }
            // Log to Google Sheets in background
            var formData = new FormData(form);
            var formType = form.getAttribute('data-form');
            logToGoogleSheet(formData, formType);
            // Form continues to submit normally to formsubmit.co
        });
    });

    // --- Show success message if redirected back after submission ---
    if (window.location.search.indexOf('submitted=true') !== -1) {
        var successMsg = document.createElement('div');
        successMsg.style.cssText = 'position:fixed;top:90px;left:50%;transform:translateX(-50%);background:#059669;color:#fff;padding:16px 32px;border-radius:12px;font-weight:600;z-index:9999;box-shadow:0 4px 20px rgba(0,0,0,0.15);';
        successMsg.textContent = 'Thank you! Your submission has been received.';
        document.body.appendChild(successMsg);
        setTimeout(function () {
            successMsg.style.transition = 'opacity 0.5s';
            successMsg.style.opacity = '0';
            setTimeout(function () { successMsg.remove(); }, 500);
        }, 5000);
        // Clean URL
        window.history.replaceState({}, '', window.location.pathname);
    }
});
