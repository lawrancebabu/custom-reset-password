(function () {
    'use strict';

    var config = window.CustomResetPassword || {};
    var i18n = config.i18n || {};

    var pass1 = document.getElementById('pass1');
    var pass2 = document.getElementById('pass2');
    var form = document.getElementById('rp-form');

    if (!pass1 || !pass2 || !form) {
        return;
    }

    var submit = document.getElementById('rp-submit-btn');
    var msgBox = document.getElementById('rp-message');
    var eyeBtn1 = document.getElementById('eye-btn-1');
    var eyeBtn2 = document.getElementById('eye-btn-2');
    var refreshBtn = document.getElementById('rp-refresh-btn');
    var bars = document.querySelectorAll('#rp-bars span');
    var strTxt = document.getElementById('rp-strength-text');
    var matTxt = document.getElementById('rp-match-text');
    var minLength = parseInt(config.minLength, 10) || 8;

    var levels = ['', i18n.weak, i18n.fair, i18n.good, i18n.strong];
    var cls = ['', 'weak', 'fair', 'good', 'strong'];
    var clrTxt = ['', '#a32d2d', '#854f0b', '#3b6d11', '#0f6e56'];

    // Cryptographically secure random integer in [0, max).
    function randomInt(max) {
        var cryptoObj = window.crypto || window.msCrypto;
        var limit = Math.floor(4294967296 / max) * max;
        var buf = new Uint32Array(1);

        do {
            cryptoObj.getRandomValues(buf);
        } while (buf[0] >= limit);

        return buf[0] % max;
    }

    function pick(chars) {
        return chars.charAt(randomInt(chars.length));
    }

    function getScore(v) {
        var s = 0;
        if (v.length >= minLength) {
            s++;
        }
        if (/[A-Z]/.test(v)) {
            s++;
        }
        if (/[0-9]/.test(v)) {
            s++;
        }
        if (/[^A-Za-z0-9]/.test(v)) {
            s++;
        }
        return s;
    }

    function generatePassword() {
        var lower = 'abcdefghijklmnopqrstuvwxyz';
        var upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        var nums = '0123456789';
        var special = '!@#$%^&*-_=+';
        var all = lower + upper + nums + special;
        var chars = [pick(lower), pick(upper), pick(nums), pick(special)];
        var i;
        var j;
        var tmp;

        for (i = 0; i < 12; i++) {
            chars.push(pick(all));
        }

        // Fisher-Yates shuffle.
        for (i = chars.length - 1; i > 0; i--) {
            j = randomInt(i + 1);
            tmp = chars[i];
            chars[i] = chars[j];
            chars[j] = tmp;
        }

        return chars.join('');
    }

    function useSuggestion(pwd) {
        pass1.value = pwd;
        pass2.value = pwd;
        pass1.focus();

        pass1.dispatchEvent(new Event('input', { bubbles: true }));
        pass2.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function createSuggestion(pwd) {
        var div = document.createElement('div');
        var span = document.createElement('span');
        var btn = document.createElement('button');

        div.className = 'rp-suggestion-item';
        span.className = 'rp-suggestion-password';
        span.textContent = pwd;

        btn.type = 'button';
        btn.className = 'rp-suggestion-btn';
        btn.textContent = i18n.use || 'Use';
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            useSuggestion(pwd);
        });

        div.appendChild(span);
        div.appendChild(btn);

        return div;
    }

    function refreshSuggestions() {
        var container = document.getElementById('rp-suggestions');
        var i;

        if (!container || !(window.crypto || window.msCrypto)) {
            return;
        }

        container.innerHTML = '';

        for (i = 0; i < 3; i++) {
            container.appendChild(createSuggestion(generatePassword()));
        }
    }

    function togglePassword(field, btn) {
        var isHidden = field.type === 'password';
        var eye = btn.querySelector('.eye-icon');
        var eyeOff = btn.querySelector('.eye-off-icon');

        field.type = isHidden ? 'text' : 'password';
        btn.setAttribute('aria-pressed', isHidden ? 'true' : 'false');

        if (eye) {
            eye.style.display = isHidden ? 'none' : '';
        }
        if (eyeOff) {
            eyeOff.style.display = isHidden ? '' : 'none';
        }
    }

    function checkMatch() {
        var v1 = pass1.value;
        var v2 = pass2.value;

        if (!v2.length) {
            matTxt.textContent = '';
            matTxt.className = 'rp-match-text';
            pass2.classList.remove('input-ok', 'input-error');
            return;
        }

        if (v1 === v2) {
            matTxt.textContent = '\u2713 ' + i18n.match;
            matTxt.className = 'rp-match-text ok';
            pass2.classList.add('input-ok');
            pass2.classList.remove('input-error');
        } else {
            matTxt.textContent = '\u2715 ' + i18n.noMatch;
            matTxt.className = 'rp-match-text err';
            pass2.classList.add('input-error');
            pass2.classList.remove('input-ok');
        }
    }

    function showMessage(type, text) {
        msgBox.className = 'rp-message ' + type;
        msgBox.textContent = text;
        msgBox.style.display = 'block';
    }

    pass1.addEventListener('input', function () {
        var v = this.value;
        var score = getScore(v);

        Array.prototype.forEach.call(bars, function (el, i) {
            el.className = (v.length && i < score) ? cls[score] : '';
        });

        if (v.length) {
            strTxt.textContent = i18n.strength + ' ' + levels[score];
            strTxt.style.color = clrTxt[score];
        } else {
            strTxt.textContent = '';
        }

        checkMatch();
    });

    pass2.addEventListener('input', checkMatch);

    form.addEventListener('submit', function (e) {
        var formData = new FormData();

        e.preventDefault();

        submit.disabled = true;
        submit.classList.add('loading');
        msgBox.style.display = 'none';

        formData.append('action', 'crp_reset_password');
        formData.append('nonce', document.getElementById('crp_nonce').value);
        formData.append('rp_login', document.getElementById('rp_login').value);
        formData.append('rp_key', document.getElementById('rp_key').value);
        formData.append('pass1', pass1.value);
        formData.append('pass2', pass2.value);

        fetch(config.ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data && data.success) {
                    showMessage('success', data.data.message);
                    setTimeout(function () {
                        window.location.href = data.data.redirect;
                    }, 1500);
                    return;
                }

                showMessage('error', (data && data.data && data.data.message) ? data.data.message : i18n.genericError);
                submit.disabled = false;
                submit.classList.remove('loading');
            })
            .catch(function () {
                showMessage('error', i18n.genericError);
                submit.disabled = false;
                submit.classList.remove('loading');
            });
    });

    if (eyeBtn1) {
        eyeBtn1.addEventListener('click', function (e) {
            e.preventDefault();
            togglePassword(pass1, eyeBtn1);
        });
    }

    if (eyeBtn2) {
        eyeBtn2.addEventListener('click', function (e) {
            e.preventDefault();
            togglePassword(pass2, eyeBtn2);
        });
    }

    if (refreshBtn) {
        refreshBtn.addEventListener('click', function (e) {
            e.preventDefault();
            refreshSuggestions();
        });
    }

    refreshSuggestions();
})();
