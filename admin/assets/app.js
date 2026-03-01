const app = document.getElementById('app');
let currentSection = 'dashboard';
let currentOffset = 0;
let logType = 'incoming';
let searchTimeout;
let selectedChatId = '';

function bytesHuman(bytes) {
  if (bytes < 1024) return `${bytes} Б`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} КБ`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} МБ`;
}

async function api(action, options = {}) {
  const method = options.method || 'GET';
  const body = options.body;
  const query = options.query ? `&${new URLSearchParams(options.query).toString()}` : '';
  const res = await fetch(`api.php?action=${action}${query}`, { method, body });
  if (!res.ok) {
    throw new Error(`Ошибка API ${res.status}`);
  }
  return res.headers.get('content-type')?.includes('application/json') ? res.json() : res.text();
}

function updateActiveNav() {
  document.querySelectorAll('.sidebar a[data-section]').forEach((link) => {
    link.classList.toggle('active', link.dataset.section === currentSection);
  });
}

function card(title, value) {
  return `<div class="card"><div class="title">${title}</div><div class="value">${value}</div></div>`;
}

async function renderDashboard() {
  const stats = await api('stats');
  app.innerHTML = `
    <h2>Дашборд</h2>
    <div class="grid">
      ${card('Всего чатов', stats.chats)}
      ${card('Размер логов', bytesHuman(stats.log_size_bytes))}
      ${card('Активность за час', stats.activity_hour)}
      ${card('Нагрузка (сообщ/мин)', stats.load)}
    </div>
    <div class="card" style="margin-top:16px">
      <h3>Последние 5 ошибок</h3>
      <div class="list">
        ${(stats.latest_errors || []).map((row) => `<div class="item">${row.raw}</div>`).join('') || '<div class="muted">Ошибок нет</div>'}
      </div>
    </div>
  `;
}

function logToolbar() {
  return `
    <div class="toolbar">
      <select id="log-type">
        <option value="incoming" ${logType === 'incoming' ? 'selected' : ''}>Входящие</option>
        <option value="error" ${logType === 'error' ? 'selected' : ''}>Ошибки</option>
        <option value="all" ${logType === 'all' ? 'selected' : ''}>Все</option>
      </select>
      <input type="date" id="filter-date">
      <input placeholder="chat_id" id="filter-chat-id">
      <select id="filter-level">
        <option value="">Любой уровень</option>
        <option value="INFO">INFO</option>
        <option value="WARN">WARN</option>
        <option value="ERROR">ERROR</option>
      </select>
      <input placeholder="Поиск" id="search">
      <button id="prev-page">← Назад</button>
      <button id="next-page">Вперёд →</button>
      <button id="clear-logs" class="button-danger">Очистить</button>
      <button id="export-logs">Экспорт</button>
    </div>
  `;
}

async function loadLogs() {
  const query = {
    type: logType,
    limit: 200,
    offset: currentOffset,
    date: document.getElementById('filter-date')?.value || '',
    chat_id: document.getElementById('filter-chat-id')?.value || '',
    level: document.getElementById('filter-level')?.value || '',
    search: document.getElementById('search')?.value || '',
  };

  const data = await api('logs', { query });
  const container = document.getElementById('logs');
  container.innerHTML = '';
  (data.items || []).forEach((line) => {
    const div = document.createElement('div');
    div.className = 'log-entry';
    div.textContent = line.raw;
    container.appendChild(div);
  });
}

async function renderLogs(sectionType) {
  logType = sectionType;
  app.innerHTML = `<h2>Логи / ${sectionType}</h2>${logToolbar()}<div class="card"><div id="logs"></div></div>`;

  document.getElementById('log-type').addEventListener('change', (e) => {
    logType = e.target.value;
    currentOffset = 0;
    loadLogs();
  });

  document.getElementById('prev-page').addEventListener('click', () => {
    currentOffset = Math.max(0, currentOffset - 200);
    loadLogs();
  });

  document.getElementById('next-page').addEventListener('click', () => {
    currentOffset += 200;
    loadLogs();
  });

  ['filter-date', 'filter-chat-id', 'filter-level'].forEach((id) => {
    document.getElementById(id).addEventListener('change', () => {
      currentOffset = 0;
      loadLogs();
    });
  });

  document.getElementById('search').addEventListener('input', () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
      currentOffset = 0;
      loadLogs();
    }, 300);
  });

  document.getElementById('clear-logs').addEventListener('click', async () => {
    const form = new FormData();
    form.append('type', logType);
    await api('clear', { method: 'POST', body: form });
    await loadLogs();
  });

  document.getElementById('export-logs').addEventListener('click', () => {
    window.location.href = `api.php?action=export&type=${encodeURIComponent(logType)}`;
  });

  await loadLogs();
}

async function renderChatLogs() {
  const chats = await api('chats');
  if (!selectedChatId && chats.length > 0) {
    selectedChatId = chats[0].chat_id;
  }

  app.innerHTML = `
    <h2>Логи чатов</h2>
    <div class="toolbar">
      <select id="chat-select">
        ${(chats || []).map((chat) => `<option value="${chat.chat_id}" ${chat.chat_id === selectedChatId ? 'selected' : ''}>${chat.title || chat.chat_id}</option>`).join('')}
      </select>
      <button id="chat-prev">← Назад</button>
      <button id="chat-next">Вперёд →</button>
    </div>
    <div class="card"><div id="chat-logs"></div></div>
  `;

  async function loadChatLogs() {
    const container = document.getElementById('chat-logs');
    if (!selectedChatId) {
      container.innerHTML = '<div class="muted">Нет чатов для отображения</div>';
      return;
    }

    const data = await api('chat_logs', {
      query: {
        chat_id: selectedChatId,
        limit: 200,
        offset: currentOffset,
      },
    });

    container.innerHTML = '';
    (data.items || []).forEach((entry) => {
      const div = document.createElement('div');
      div.className = 'log-entry';
      div.textContent = JSON.stringify(entry, null, 2);
      container.appendChild(div);
    });

    if (!data.items || data.items.length === 0) {
      container.innerHTML = '<div class="muted">Записей нет</div>';
    }
  }

  document.getElementById('chat-select').addEventListener('change', (e) => {
    selectedChatId = e.target.value;
    currentOffset = 0;
    loadChatLogs();
  });

  document.getElementById('chat-prev').addEventListener('click', () => {
    currentOffset = Math.max(0, currentOffset - 200);
    loadChatLogs();
  });

  document.getElementById('chat-next').addEventListener('click', () => {
    currentOffset += 200;
    loadChatLogs();
  });

  await loadChatLogs();
}

async function renderChats() {
  const chats = await api('chats');
  app.innerHTML = `<h2>Чаты</h2><div class="list">${(chats || []).map((chat) => `
      <div class="item">
        <strong>${chat.title || 'Без названия'}</strong><br>
        <span class="muted">ID: ${chat.chat_id || chat.id || '-'}</span>
      </div>`).join('') || '<div class="muted">Нет чатов</div>'}
    </div>`;
}

async function renderFiles() {
  const files = await api('files');
  app.innerHTML = `<h2>Файлы</h2><div class="list">${(files || []).map((file) => `
      <div class="item"><strong>${file.name || 'файл'}</strong> <span class="muted">${file.size_formatted || ''}</span></div>
  `).join('') || '<div class="muted">Файлов нет</div>'}</div>`;
}

function renderSettings() {
  app.innerHTML = '<h2>Настройки</h2><div class="card"><p class="muted">Настройки безопасности и 2FA можно расширить через admin/auth.php и config.php.</p></div>';
}

async function route(section) {
  currentSection = section;
  currentOffset = 0;
  updateActiveNav();

  if (section === 'dashboard') return renderDashboard();
  if (section === 'incoming') return renderLogs('incoming');
  if (section === 'error') return renderLogs('error');
  if (section === 'messages') return renderChatLogs();
  if (section === 'chats') return renderChats();
  if (section === 'files') return renderFiles();
  return renderSettings();
}

document.querySelectorAll('.sidebar a[data-section]').forEach((link) => {
  link.addEventListener('click', (e) => {
    e.preventDefault();
    route(link.dataset.section);
  });
});

document.getElementById('logout-btn')?.addEventListener('click', async () => {
  await api('logout');
  window.location.href = 'index.php';
});

route('dashboard');
setInterval(() => {
  if (currentSection === 'incoming' || currentSection === 'error') {
    loadLogs();
  }
}, 5000);
