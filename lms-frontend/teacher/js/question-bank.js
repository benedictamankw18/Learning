(function () {
  'use strict';

  const state = {
    questions: [],
    pagination: { current_page: 1, per_page: 25, total: 0, total_pages: 1 },
    filters: { q: '', category_id: '', subject_id: '', topic_id: '', approval_status: '', visibility: '', is_archived: null },
    categories: [],
    subjects: [],
    topics: [],
    courses: [],
    editingId: null,
    pendingQuizQuestionId: null,
    bound: false,
  };

  let els = {};

  function hasPageRoot() {
    return !!document.getElementById('teacherQuestionBankRoot');
  }

  function cacheElements() {
    els = {
      root: document.getElementById('teacherQuestionBankRoot'),
      notice: document.getElementById('qbNotice'),
      search: document.getElementById('qbSearch'),
      filterCategory: document.getElementById('qbFilterCategory'),
      filterSubject: document.getElementById('qbFilterSubject'),
      filterTopic: document.getElementById('qbFilterTopic'),
      filterStatus: document.getElementById('qbFilterStatus'),
      filterOwnership: document.getElementById('qbFilterOwnership'),
      refreshBtn: document.getElementById('qbRefreshBtn'),
      newBtn: document.getElementById('qbNewBtn'),
      importBtn: document.getElementById('qbImportBtn'),
      exportBtn: document.getElementById('qbExportBtn'),
      templateBtn: document.getElementById('qbTemplateBtn'),
      importModal: document.getElementById('qbImportModal'),
      importClose: document.getElementById('qbImportClose'),
      importCancel: document.getElementById('qbImportCancelBtn'),
      importConfirm: document.getElementById('qbImportConfirmBtn'),
      importFile: document.getElementById('qbImportFile'),
      importTemplateBtn: document.getElementById('qbImportTemplateBtn'),
      importResults: document.getElementById('qbImportResults'),
      listBody: document.getElementById('qbListBody'),
      pagination: document.getElementById('qbPagination'),

      editorModal: document.getElementById('qbEditorModal'),
      editorTitle: document.getElementById('qbEditorTitle'),
      editorClose: document.getElementById('qbEditorClose'),
      editorCancel: document.getElementById('qbEditorCancel'),
      editorSave: document.getElementById('qbEditorSave'),
      questionText: document.getElementById('qbQuestionText'),
      questionType: document.getElementById('qbQuestionType'),
      points: document.getElementById('qbPoints'),
      difficulty: document.getElementById('qbDifficulty'),
      correctAnswer: document.getElementById('qbCorrectAnswer'),
      category: document.getElementById('qbCategory'),
      subject: document.getElementById('qbSubject'),
      topic: document.getElementById('qbTopic'),
      imageQuestion: document.getElementById('qbImageQuestion'),
      explanation: document.getElementById('qbExplanation'),
      optionsField: document.getElementById('qbOptionsField'),
      optionsList: document.getElementById('qbOptionsList'),
      addOptionBtn: document.getElementById('qbAddOptionBtn'),

      versionsModal: document.getElementById('qbVersionsModal'),
      versionsTitle: document.getElementById('qbVersionsTitle'),
      versionsBody: document.getElementById('qbVersionsBody'),
      versionsClose: document.getElementById('qbVersionsClose'),
      versionsDone: document.getElementById('qbVersionsDone'),

      quizModal: document.getElementById('qbQuizModal'),
      quizClose: document.getElementById('qbQuizClose'),
      quizCancel: document.getElementById('qbQuizCancel'),
      quizConfirm: document.getElementById('qbQuizConfirm'),
      quizCourse: document.getElementById('qbQuizCourse'),
      quizSelect: document.getElementById('qbQuizSelect'),
      quizHint: document.getElementById('qbQuizHint'),
    };
  }

  function escapeHtml(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function setNotice(message, type) {
    if (!els.notice) return;
    if (!message) {
      els.notice.className = 'qb-notice';
      els.notice.textContent = '';
      return;
    }
    els.notice.className = 'qb-notice ' + (type || 'success');
    els.notice.textContent = message;
  }

  function normalizeRows(response, key) {
    if (!response) return [];
    const data = response.data || response;
    if (Array.isArray(data)) return data;
    if (Array.isArray(data[key])) return data[key];
    if (Array.isArray(data.rows)) return data.rows;
    return [];
  }

  function typeLabel(type) {
    const map = {
      multiple_choice: 'Multiple choice',
      true_false: 'True / False',
      short_answer: 'Short answer',
      essay: 'Essay',
    };
    return map[type] || type || '-';
  }

  function statusBadge(status) {
    const s = String(status || 'draft');
    return '<span class="qb-badge qb-badge-' + escapeHtml(s) + '">' + escapeHtml(s) + '</span>';
  }

  function visibilityBadge(v) {
    const value = String(v || 'private');
    return '<span class="qb-badge qb-badge-' + escapeHtml(value) + '">' + escapeHtml(value) + '</span>';
  }

  function openModal(modal) { if (modal) modal.classList.add('open'); }
  function closeModal(modal) { if (modal) modal.classList.remove('open'); }

  function currentUser() {
    return (typeof Auth !== 'undefined' && Auth.getUser) ? Auth.getUser() : null;
  }

  /* ---------------- Taxonomy + filters ---------------- */

  async function loadTaxonomy() {
    try {
      const [catRes, subRes, topicRes] = await Promise.all([
        QuestionBankAPI.getCategories(),
        SubjectAPI.getAll({ limit: 500 }),
        QuestionBankAPI.getTopics(),
      ]);
      state.categories = normalizeRows(catRes, 'categories');
      state.subjects = normalizeRows(subRes, 'subjects');
      state.topics = normalizeRows(topicRes, 'topics');
    } catch (error) {
      console.warn('Failed to load taxonomy:', error);
    }
  }

  function fillSelect(select, rows, labelKey, valueKey, placeholder, selected) {
    if (!select) return;
    select.innerHTML = '<option value="">' + escapeHtml(placeholder) + '</option>';
    rows.forEach(function (row) {
      const option = document.createElement('option');
      option.value = String(row[valueKey]);
      option.textContent = String(row[labelKey]);
      if (String(selected || '') === String(row[valueKey])) option.selected = true;
      select.appendChild(option);
    });
  }

  function populateSelects() {
    fillSelect(els.filterCategory, state.categories, 'name', 'category_id', 'All categories', state.filters.category_id);
    fillSelect(els.filterSubject, state.subjects, 'subject_name', 'subject_id', 'All subjects', state.filters.subject_id);

    let topics = state.topics;
    if (state.filters.subject_id) {
      topics = topics.filter(function (t) {
        return !t.subject_id || String(t.subject_id) === String(state.filters.subject_id);
      });
    }
    fillSelect(els.filterTopic, topics, 'name', 'topic_id', 'All topics', state.filters.topic_id);

    fillSelect(els.category, state.categories, 'name', 'category_id', 'None', null);
    fillSelect(els.subject, state.subjects, 'subject_name', 'subject_id', 'None', null);
    const editTopics = state.filters.subject_id
      ? state.topics.filter(function (t) { return !t.subject_id || String(t.subject_id) === String(state.filters.subject_id); })
      : state.topics;
    fillSelect(els.topic, editTopics, 'name', 'topic_id', 'None', null);
  }

  /* ---------------- Question list ---------------- */

  function buildFilters() {
    return {
      q: state.filters.q,
      category_id: state.filters.category_id,
      subject_id: state.filters.subject_id,
      topic_id: state.filters.topic_id,
      approval_status: state.filters.approval_status,
      visibility: state.filters.visibility,
      is_archived: state.filters.is_archived,
      page: state.pagination.current_page,
      limit: state.pagination.per_page,
    };
  }

  function cleanParams(params) {
    const out = {};
    Object.keys(params).forEach(function (key) {
      const v = params[key];
      if (v !== null && v !== undefined && v !== '') out[key] = v;
    });
    return out;
  }

  async function loadQuestions() {
    if (els.listBody) els.listBody.innerHTML = '<tr><td colspan="8" class="qb-empty">Loading questions...</td></tr>';
    try {
      const response = await QuestionBankAPI.getQuestions(cleanParams(buildFilters()));
      const data = response.data || {};
      state.questions = Array.isArray(data.questions) ? data.questions : [];
      const p = data.pagination || {};
      state.pagination = {
        current_page: Number(p.current_page) || 1,
        per_page: Number(p.per_page) || 25,
        total: Number(p.total) || 0,
        total_pages: Number(p.total_pages) || 1,
      };
      renderTable();
      renderPagination();
      setNotice('');
    } catch (error) {
      if (els.listBody) {
        els.listBody.innerHTML = '<tr><td colspan="8" class="qb-empty">Failed to load questions.</td></tr>';
      }
      setNotice(error.message || 'Unable to load questions.', 'error');
    }
  }

  function renderTable() {
    if (!els.listBody) return;
    if (state.questions.length === 0) {
      els.listBody.innerHTML = '<tr><td colspan="8" class="qb-empty">No questions match your filters.</td></tr>';
      return;
    }

    const user = currentUser() || {};
    const userId = Number(user.user_id || 0);
    const isAdmin = !!(user.is_super_admin) || String(user.role || '').toLowerCase() === 'admin'
      || (Array.isArray(user.roles) && user.roles.some(function (r) { return String(r).toLowerCase() === 'admin'; }));

    els.listBody.innerHTML = state.questions.map(function (q) {
      const text = String(q.question_text || '');
      const preview = text.length > 150 ? text.substring(0, 150) + '…' : text;
      const owns = Number(q.owner_user_id || 0) === userId;
      const canEdit = isAdmin || owns;
      const approved = String(q.approval_status) === 'approved';

      const actions = [];
      if (canEdit) {
        actions.push('<button class="qb-btn qb-btn-secondary qb-btn-sm" data-action="edit" data-id="' + q.question_id + '">Edit</button>');
        if (!approved) {
          actions.push('<button class="qb-btn qb-btn-secondary qb-btn-sm" data-action="submit" data-id="' + q.question_id + '">Submit</button>');
        }
        const archived = Number(q.is_archived) === 1;
        actions.push('<button class="qb-btn qb-btn-secondary qb-btn-sm" data-action="archive" data-id="' + q.question_id + '" data-archived="' + archived + '">' + (archived ? 'Restore' : 'Archive') + '</button>');
      } else {
        actions.push('<button class="qb-btn qb-btn-secondary qb-btn-sm" data-action="fork" data-id="' + q.question_id + '">Fork</button>');
      }
      actions.push('<button class="qb-btn qb-btn-secondary qb-btn-sm" data-action="versions" data-id="' + q.question_id + '">Versions</button>');
      actions.push('<button class="qb-btn qb-btn-primary qb-btn-sm" data-action="addtoquiz" data-id="' + q.question_id + '">Add to quiz</button>');

      const categoryTopic = [q.category_name, q.topic_name].filter(Boolean).map(escapeHtml).join(' / ') || '-';

      return '<tr>' +
        '<td class="qb-text-preview" title="' + escapeHtml(text) + '">' + escapeHtml(preview) + '</td>' +
        '<td>' + escapeHtml(typeLabel(q.question_type)) + '</td>' +
        '<td>' + categoryTopic + '</td>' +
        '<td>' + escapeHtml(q.points) + '</td>' +
        '<td>' + statusBadge(q.approval_status) + '</td>' +
        '<td>' + visibilityBadge(q.visibility) + '</td>' +
        '<td>' + escapeHtml(q.version_count != null ? q.version_count : (q.current_version_id ? '≥1' : '0')) + '</td>' +
        '<td><div class="qb-row-actions">' + actions.join('') + '</div></td>' +
        '</tr>';
    }).join('');
  }

  function renderPagination() {
    if (!els.pagination) return;
    const p = state.pagination;
    if (!p.total_pages || p.total_pages <= 1) {
      els.pagination.innerHTML = '<span style="font-size:0.8rem;color:#64748b;">' + p.total + ' question(s)</span>';
      return;
    }
    let html = '<span style="font-size:0.8rem;color:#64748b;margin-right:0.5rem;">' + p.total + ' question(s)</span>';
    html += '<button data-page="' + (p.current_page - 1) + '"' + (p.current_page <= 1 ? ' disabled' : '') + '>‹</button>';
    for (let i = 1; i <= p.total_pages; i++) {
      html += '<button data-page="' + i + '"' + (i === p.current_page ? ' class="active"' : '') + '>' + i + '</button>';
    }
    html += '<button data-page="' + (p.current_page + 1) + '"' + (p.current_page >= p.total_pages ? ' disabled' : '') + '>›</button>';
    els.pagination.innerHTML = html;
  }

  /* ---------------- Editor ---------------- */

  function resetEditor() {
    state.editingId = null;
    if (!els.editorTitle) return;
    els.editorTitle.textContent = 'New Question';
    els.questionText.value = '';
    els.questionType.value = 'multiple_choice';
    els.points.value = '1';
    els.difficulty.value = 'medium';
    els.correctAnswer.value = '';
    els.category.value = '';
    els.subject.value = '';
    els.topic.value = '';
    els.imageQuestion.value = '';
    els.explanation.value = '';
    els.optionsList.innerHTML = '';
    addOptionRow('A', '', false);
    addOptionRow('B', '', false);
    toggleOptionsField();
  }

  function toggleOptionsField() {
    if (els.optionsField) {
      els.optionsField.style.display = els.questionType.value === 'multiple_choice' ? '' : 'none';
    }
  }

  function addOptionRow(label, text, isCorrect) {
    if (!els.optionsList) return;
    const row = document.createElement('div');
    row.className = 'qb-option-row';
    row.innerHTML =
      '<input type="text" class="qb-opt-label" maxlength="5" value="' + escapeHtml(label || '') + '" style="width:48px;" />' +
      '<input type="text" class="qb-opt-text" value="' + escapeHtml(text || '') + '" placeholder="Option text" />' +
      '<label style="font-size:0.75rem;white-space:nowrap;"><input type="checkbox" class="qb-opt-correct"' + (isCorrect ? ' checked' : '') + ' /> Correct</label>' +
      '<button class="qb-btn qb-btn-danger qb-btn-sm" type="button" data-action="remove-option">&times;</button>';
    els.optionsList.appendChild(row);
  }

  async function loadOptionsIntoEditor(questionId) {
    if (!els.optionsList) return;
    els.optionsList.innerHTML = '';
    try {
      const response = await QuestionBankAPI.getVersions(questionId);
      const versions = normalizeRows(response, 'versions');
      let options = [];
      if (versions.length) {
        const content = versions[0].content || {};
        options = Array.isArray(content.options) ? content.options : [];
      }
      if (!options.length) {
        options = [{ option_label: 'A', option_text: '', is_correct: 0 }, { option_label: 'B', option_text: '', is_correct: 0 }];
      }
      options.forEach(function (o) {
        addOptionRow(o.option_label, o.option_text, Number(o.is_correct) === 1);
      });
    } catch (error) {
      addOptionRow('A', '', false);
      addOptionRow('B', '', false);
    }
  }

  function fillEditor(q) {
    state.editingId = q.question_id;
    els.editorTitle.textContent = 'Edit Question #' + q.question_id;
    els.questionText.value = q.question_text || '';
    els.questionType.value = q.question_type || 'short_answer';
    els.points.value = q.points != null ? q.points : 1;
    els.difficulty.value = q.difficulty || 'medium';
    els.correctAnswer.value = q.correct_answer || '';
    els.category.value = q.category_id || '';
    els.subject.value = q.subject_id || '';
    els.topic.value = q.topic_id || '';
    els.imageQuestion.value = q.image_question || '';
    els.explanation.value = q.explanation || '';
    toggleOptionsField();
    openModal(els.editorModal);
    if (els.questionType.value === 'multiple_choice') loadOptionsIntoEditor(q.question_id);
  }

  function collectOptions() {
    const rows = els.optionsList ? els.optionsList.querySelectorAll('.qb-option-row') : [];
    const options = [];
    rows.forEach(function (row) {
      const label = String(row.querySelector('.qb-opt-label').value || '').trim().toUpperCase();
      const text = String(row.querySelector('.qb-opt-text').value || '').trim();
      const isCorrect = row.querySelector('.qb-opt-correct').checked ? 1 : 0;
      if (label && text) options.push({ label: label, text: text, is_correct: isCorrect });
    });
    return options;
  }

  function buildPayload() {
    const payload = {
      question_text: String(els.questionText.value || '').trim(),
      question_type: els.questionType.value,
      points: Number(els.points.value) || 0,
      difficulty: els.difficulty.value,
      correct_answer: String(els.correctAnswer.value || '').trim(),
      explanation: String(els.explanation.value || '').trim(),
      image_question: String(els.imageQuestion.value || '').trim() || null,
      category_id: els.category.value || null,
      subject_id: els.subject.value || null,
      topic_id: els.topic.value || null,
    };
    if (els.questionType.value === 'multiple_choice') {
      payload.options = collectOptions();
    }
    return payload;
  }

  async function saveQuestion() {
    const payload = buildPayload();
    if (!payload.question_text) {
      setNotice('Question text is required.', 'error');
      return;
    }
    if (payload.question_type === 'multiple_choice' && (!payload.options || payload.options.length < 2)) {
      setNotice('Multiple choice questions require at least two options.', 'error');
      return;
    }
    try {
      if (state.editingId) {
        await QuestionBankAPI.updateQuestion(state.editingId, payload);
        showToast('Question updated', 'success');
      } else {
        await QuestionBankAPI.createQuestion(payload);
        showToast('Question created', 'success');
      }
      closeModal(els.editorModal);
      await loadQuestions();
    } catch (error) {
      setNotice(error.message || 'Unable to save question.', 'error');
    }
  }

  /* ---------------- Actions ---------------- */

  async function submitForApproval(id) {
    try {
      await QuestionBankAPI.submitForApproval(id);
      showToast('Submitted for approval', 'success');
      await loadQuestions();
    } catch (error) {
      setNotice(error.message || 'Unable to submit question.', 'error');
    }
  }

  async function forkQuestion(id) {
    try {
      await QuestionBankAPI.fork(id);
      showToast('Question copied to your bank', 'success');
      await loadQuestions();
    } catch (error) {
      setNotice(error.message || 'Unable to fork question.', 'error');
    }
  }

  async function archiveQuestion(id, archived) {
    try {
      await QuestionBankAPI.archive(id, archived === 'true' || archived === true ? false : true);
      showToast(archived ? 'Question restored' : 'Question archived', 'success');
      await loadQuestions();
    } catch (error) {
      setNotice(error.message || 'Unable to update question.', 'error');
    }
  }

  async function showVersions(id) {
    if (!els.versionsBody) return;
    els.versionsBody.innerHTML = '<p>Loading versions...</p>';
    els.versionsTitle.textContent = 'Version History #' + id;
    openModal(els.versionsModal);
    try {
      const response = await QuestionBankAPI.getVersions(id);
      const versions = normalizeRows(response, 'versions');
      if (!versions.length) {
        els.versionsBody.innerHTML = '<p>No versions found.</p>';
        return;
      }
      els.versionsBody.innerHTML = versions.map(function (v) {
        const c = v.content || {};
        const opts = Array.isArray(c.options) ? c.options : [];
        const optionsHtml = opts.length
          ? '<p><strong>Options:</strong> ' + opts.map(function (o) {
              return escapeHtml(o.option_label) + '. ' + escapeHtml(o.option_text) + (Number(o.is_correct) === 1 ? ' ✓' : '');
            }).join('; ') + '</p>'
          : '';
        return '<div class="qb-version">' +
          '<h4>Version ' + escapeHtml(v.version_number) + ' <span style="font-weight:normal;color:#64748b;font-size:0.75rem;">' + escapeHtml(v.created_at || '') + ' by ' + escapeHtml(v.created_by_username || 'system') + '</span></h4>' +
          '<p><strong>Text:</strong> ' + escapeHtml(c.question_text) + '</p>' +
          '<p><strong>Type:</strong> ' + escapeHtml(typeLabel(c.question_type)) + ' · <strong>Points:</strong> ' + escapeHtml(c.points) + ' · <strong>Difficulty:</strong> ' + escapeHtml(c.difficulty) + '</p>' +
          '<p><strong>Correct answer:</strong> ' + escapeHtml(c.correct_answer || '-') + '</p>' +
          optionsHtml +
          '</div>';
      }).join('');
    } catch (error) {
      els.versionsBody.innerHTML = '<p>Failed to load versions.</p>';
    }
  }

  /* ---------------- Add to quiz ---------------- */

  async function loadPickerCourses() {
    if (!els.quizCourse) return;
    els.quizCourse.innerHTML = '<option value="">Loading courses...</option>';
    els.quizSelect.innerHTML = '<option value="">Select a course first</option>';
    try {
      const user = currentUser() || {};
      let courses = [];
      if (user.teacher_uuid) {
        try {
          const res = await TeacherAPI.getCourses(user.teacher_uuid, { limit: 500 });
          courses = normalizeRows(res, 'courses');
        } catch (e) { /* fall through */ }
      }
      if (!courses.length && typeof DashboardAPI !== 'undefined' && DashboardAPI.getTeacherStats) {
        try {
          const res = await DashboardAPI.getTeacherStats();
          courses = normalizeRows(res, 'courses');
        } catch (e) { /* fall through */ }
      }
      state.courses = courses;
      els.quizCourse.innerHTML = '<option value="">Select a course</option>';
      state.courses.forEach(function (c) {
        const option = document.createElement('option');
        option.value = String(c.course_id || c.id);
        option.textContent = String(c.course_name || c.title || c.name || ('Course #' + (c.course_id || c.id)));
        els.quizCourse.appendChild(option);
      });
    } catch (error) {
      els.quizCourse.innerHTML = '<option value="">Failed to load courses</option>';
    }
  }

  async function loadPickerQuizzes(courseId) {
    if (!els.quizSelect) return;
    if (!courseId) {
      els.quizSelect.innerHTML = '<option value="">Select a course first</option>';
      return;
    }
    els.quizSelect.innerHTML = '<option value="">Loading quizzes...</option>';
    try {
      const res = await QuizAPI.getByCourse(courseId);
      const quizzes = normalizeRows(res, 'quizzes');
      els.quizSelect.innerHTML = '<option value="">Select a quiz</option>';
      quizzes.forEach(function (quiz) {
        const option = document.createElement('option');
        option.value = String(quiz.quiz_id || quiz.id);
        option.textContent = String(quiz.title || ('Quiz #' + (quiz.quiz_id || quiz.id)));
        els.quizSelect.appendChild(option);
      });
    } catch (error) {
      els.quizSelect.innerHTML = '<option value="">Failed to load quizzes</option>';
    }
  }

  function openQuizModal(questionId) {
    state.pendingQuizQuestionId = questionId;
    els.quizHint.textContent = 'This links question #' + questionId + ' into the quiz without copying it.';
    els.quizCourse.value = '';
    els.quizSelect.innerHTML = '<option value="">Select a course first</option>';
    openModal(els.quizModal);
    loadPickerCourses();
  }

  async function confirmLink() {
    const quizId = els.quizSelect ? Number(els.quizSelect.value) : 0;
    if (!quizId) {
      setNotice('Select a quiz first.', 'error');
      return;
    }
    try {
      await QuizAPI.linkExistingQuestion(quizId, state.pendingQuizQuestionId);
      showToast('Question added to quiz', 'success');
      closeModal(els.quizModal);
      state.pendingQuizQuestionId = null;
    } catch (error) {
      setNotice(error.message || 'Unable to add question to quiz.', 'error');
    }
  }

  /* ---------------- Events ---------------- */

  function renderCsvImportResults(data) {
    if (!els.importResults) return;
    const imported = Number(data.imported || 0);
    const skipped = Number(data.skipped || 0);
    const failed = Number(data.failed || 0);
    const results = Array.isArray(data.results) ? data.results : [];
    const summary = `Imported ${imported} · Skipped ${skipped} · Failed ${failed}`;
    const rows = results.map(function (result) {
      const status = String(result.status || 'failed').toLowerCase();
      const safeStatus = ['imported', 'skipped', 'failed'].includes(status) ? status : 'failed';
      return '<div class="qb-import-results-row ' + safeStatus + '">'
        + '<strong>Row ' + escapeHtml(result.row) + ':</strong> '
        + escapeHtml(safeStatus) + ' — ' + escapeHtml(result.message || '')
        + '</div>';
    }).join('');
    els.importResults.innerHTML = '<div style="font-weight:600; margin-bottom:0.35rem">'
      + escapeHtml(summary) + '</div>'
      + (rows || '<p class="qb-import-hint">No row details were returned.</p>');
    els.importResults.style.display = 'block';
  }

  function openCsvImportModal() {
    if (els.importFile) els.importFile.value = '';
    if (els.importResults) {
      els.importResults.innerHTML = '';
      els.importResults.style.display = 'none';
    }
    if (els.importConfirm) {
      els.importConfirm.disabled = true;
      els.importConfirm.textContent = 'Import Questions';
    }
    openModal(els.importModal);
  }

  function syncCsvImportButton() {
    const file = els.importFile && els.importFile.files ? els.importFile.files[0] : null;
    const isCsv = !!file && /\.csv$/i.test(file.name);
    if (els.importConfirm) els.importConfirm.disabled = !isCsv;
  }

  async function downloadCsvTemplate() {
    try {
      await QuestionBankAPI.downloadImportTemplate();
      setNotice('Import template downloaded.', 'success');
    } catch (error) {
      setNotice(error.message || 'Failed to download the import template.', 'error');
    }
  }

  async function exportCsv() {
    try {
      await QuestionBankAPI.exportQuestions(state.filters);
      setNotice('Questions exported as CSV.', 'success');
    } catch (error) {
      setNotice(error.message || 'Failed to export questions.', 'error');
    }
  }

  async function importCsv() {
    const file = els.importFile && els.importFile.files ? els.importFile.files[0] : null;
    if (!file) {
      setNotice('Choose a CSV file to import.', 'error');
      return;
    }
    if (!/\.csv$/i.test(file.name)) {
      setNotice('Please choose a .csv file.', 'error');
      return;
    }

    const formData = new FormData();
    formData.append('file', file);
    const button = els.importConfirm;
    const originalLabel = button ? button.textContent : '';
    if (button) {
      button.disabled = true;
      button.textContent = 'Importing...';
    }

    try {
      const response = await QuestionBankAPI.importQuestions(formData);
      const data = response && response.data ? response.data : {};
      const imported = Number(data.imported || 0);
      const skipped = Number(data.skipped || 0);
      const failed = Number(data.failed || 0);

      renderCsvImportResults(data);
      setNotice(
        `Imported ${imported}, skipped ${skipped}, failed ${failed}.`,
        failed > 0 ? 'error' : 'success'
      );
      if (imported > 0) {
        await loadQuestions();
      }
      if (els.importFile) els.importFile.value = '';
    } catch (error) {
      renderCsvImportResults({
        imported: 0, skipped: 0, failed: 1,
        results: [{ row: '-', status: 'failed', message: error.message || 'The import request failed.' }]
      });
      setNotice(error.message || 'Failed to import questions.', 'error');
    } finally {
      if (button) {
        button.textContent = originalLabel || 'Import Questions';
        syncCsvImportButton();
      }
    }
  }

  function bindCsvEvents() {
    els.importBtn?.addEventListener('click', openCsvImportModal);
    els.exportBtn?.addEventListener('click', exportCsv);
    els.templateBtn?.addEventListener('click', downloadCsvTemplate);
    els.importTemplateBtn?.addEventListener('click', downloadCsvTemplate);
    els.importClose?.addEventListener('click', function () { closeModal(els.importModal); });
    els.importCancel?.addEventListener('click', function () { closeModal(els.importModal); });
    els.importConfirm?.addEventListener('click', importCsv);
    els.importFile?.addEventListener('change', syncCsvImportButton);
    els.importModal?.addEventListener('click', function (e) {
      if (e.target === els.importModal) closeModal(els.importModal);
    });
  }

  function bindEvents() {
    els.refreshBtn?.addEventListener('click', function () { loadQuestions(); });
    els.newBtn?.addEventListener('click', function () { resetEditor(); openModal(els.editorModal); });

    let searchTimer = null;
    els.search?.addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        state.filters.q = els.search.value.trim();
        state.pagination.current_page = 1;
        loadQuestions();
      }, 300);
    });

    els.filterCategory?.addEventListener('change', function () {
      state.filters.category_id = els.filterCategory.value;
      state.pagination.current_page = 1;
      loadQuestions();
    });
    els.filterSubject?.addEventListener('change', function () {
      state.filters.subject_id = els.filterSubject.value;
      state.filters.topic_id = '';
      state.pagination.current_page = 1;
      populateSelects();
      loadQuestions();
    });
    els.filterTopic?.addEventListener('change', function () {
      state.filters.topic_id = els.filterTopic.value;
      state.pagination.current_page = 1;
      loadQuestions();
    });
    els.filterStatus?.addEventListener('change', function () {
      state.filters.approval_status = els.filterStatus.value;
      state.pagination.current_page = 1;
      loadQuestions();
    });
    els.filterOwnership?.addEventListener('change', function () {
      const value = els.filterOwnership.value;
      state.filters.visibility = (value === 'private' || value === 'institution') ? value : '';
      state.filters.is_archived = value === 'archived' ? 1 : null;
      state.pagination.current_page = 1;
      loadQuestions();
    });

    els.pagination?.addEventListener('click', function (e) {
      const btn = e.target.closest('button[data-page]');
      if (!btn || btn.disabled) return;
      state.pagination.current_page = Number(btn.getAttribute('data-page')) || 1;
      loadQuestions();
    });

    els.listBody?.addEventListener('click', function (e) {
      const btn = e.target.closest('button[data-action]');
      if (!btn) return;
      const id = Number(btn.getAttribute('data-id'));
      const action = btn.getAttribute('data-action');
      const q = state.questions.find(function (item) { return Number(item.question_id) === id; });
      if (action === 'edit' && q) fillEditor(q);
      else if (action === 'submit') submitForApproval(id);
      else if (action === 'fork') forkQuestion(id);
      else if (action === 'archive') archiveQuestion(id, btn.getAttribute('data-archived'));
      else if (action === 'versions') showVersions(id);
      else if (action === 'addtoquiz') openQuizModal(id);
    });

    els.editorClose?.addEventListener('click', function () { closeModal(els.editorModal); });
    els.editorCancel?.addEventListener('click', function () { closeModal(els.editorModal); });
    els.editorSave?.addEventListener('click', saveQuestion);
    els.questionType?.addEventListener('change', toggleOptionsField);
    els.addOptionBtn?.addEventListener('click', function () { addOptionRow('', '', false); });
    els.optionsList?.addEventListener('click', function (e) {
      const btn = e.target.closest('button[data-action="remove-option"]');
      if (btn) btn.closest('.qb-option-row')?.remove();
    });

    els.versionsClose?.addEventListener('click', function () { closeModal(els.versionsModal); });
    els.versionsDone?.addEventListener('click', function () { closeModal(els.versionsModal); });

    els.quizClose?.addEventListener('click', function () { closeModal(els.quizModal); });
    els.quizCancel?.addEventListener('click', function () { closeModal(els.quizModal); });
    els.quizConfirm?.addEventListener('click', confirmLink);
    els.quizCourse?.addEventListener('change', function () { loadPickerQuizzes(els.quizCourse.value); });

    document.querySelectorAll('.qb-modal-overlay').forEach(function (overlay) {
      overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeModal(overlay);
      });
    });
  }

  async function initPage() {
    if (!hasPageRoot()) return;
    cacheElements();
    if (!state.bound) {
      bindEvents();
      state.bound = true;
    }
    // The import modal lives in the page fragment, so its nodes are recreated
    // on every navigation. Bind its events each time against the fresh nodes.
    bindCsvEvents();
    state.pagination.current_page = 1;
    await loadTaxonomy();
    populateSelects();
    await loadQuestions();
  }

  document.addEventListener('page:loaded', function (event) {
    if (event && event.detail && event.detail.page === 'question-bank') {
      initPage();
    }
  });
})();
