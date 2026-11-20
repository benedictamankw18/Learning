(function () {
  'use strict';

  const state = {
    questions: [],
    pagination: { current_page: 1, per_page: 25, total: 0, total_pages: 1 },
    filters: { q: '', course_section_id: '', include_archived: 0 },
    teacherCourses: [],
    courseSections: [],
    courses: [],
    editingId: null,
    editingImageUrl: '',
    pendingQuizQuestionId: null,
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
      filterCourseSection: document.getElementById('qbFilterCourseSection'),
      includeArchived: document.getElementById('qbIncludeArchived'),
      refreshBtn: document.getElementById('qbRefreshBtn'),
      newBtn: document.getElementById('qbNewBtn'),
      importBtn: document.getElementById('qbImportBtn'),
      exportBtn: document.getElementById('qbExportBtn'),
      templateBtn: document.getElementById('qbTemplateBtn'),

      editorCard: document.getElementById('teacherQuestionBankEditorCard'),
      form: document.getElementById('qbQuestionForm'),
      editorTitle: document.getElementById('qbEditorTitle'),
      questionId: document.getElementById('qbQuestionId'),
      questionText: document.getElementById('qbQuestionText'),
      questionType: document.getElementById('qbQuestionType'),
      points: document.getElementById('qbPoints'),
      difficulty: document.getElementById('qbDifficulty'),
      imageQuestion: document.getElementById('qbImageQuestion'),
      imageQuestionCurrent: document.getElementById('qbImageQuestionCurrent'),
      courseSection: document.getElementById('qbCourseSection'),
      optionA: document.getElementById('qbOptionA'),
      optionB: document.getElementById('qbOptionB'),
      optionC: document.getElementById('qbOptionC'),
      optionD: document.getElementById('qbOptionD'),
      correctOption: document.getElementById('qbCorrectOption'),
      trueValue: document.getElementById('qbTrueValue'),
      falseValue: document.getElementById('qbFalseValue'),
      correctAnswer: document.getElementById('qbCorrectAnswer'),
      optionsBlock: document.getElementById('qbOptionsBlock'),
      trueFalseBlock: document.getElementById('qbTrueFalseBlock'),
      correctAnswerField: document.getElementById('qbCorrectAnswerField'),
      explanation: document.getElementById('qbExplanation'),
      clearBtn: document.getElementById('qbClearBtn'),
      submitBtn: document.getElementById('qbSubmitBtn'),

      listBody: document.getElementById('qbListBody'),
      pagination: document.getElementById('qbPagination'),

      quizModal: document.getElementById('qbQuizModal'),
      quizClose: document.getElementById('qbQuizClose'),
      quizCancel: document.getElementById('qbQuizCancel'),
      quizConfirm: document.getElementById('qbQuizConfirm'),
      quizCourse: document.getElementById('qbQuizCourse'),
      quizSelect: document.getElementById('qbQuizSelect'),
      quizHint: document.getElementById('qbQuizHint'),

      importModal: document.getElementById('qbImportModal'),
      importClose: document.getElementById('qbImportClose'),
      importCancel: document.getElementById('qbImportCancelBtn'),
      importConfirm: document.getElementById('qbImportConfirmBtn'),
      importFile: document.getElementById('qbImportFile'),
      importTemplateBtn: document.getElementById('qbImportTemplateBtn'),
      importResults: document.getElementById('qbImportResults'),
    };
  }

  function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = String(value === null || value === undefined ? '' : value);
    return div.innerHTML;
  }

  function setNotice(message, type) {
    if (!els.notice) return;
    if (!message) {
      els.notice.className = 'qb-notice';
      els.notice.textContent = '';
      return;
    }
    const safeType = ['success', 'error', 'info'].includes(type) ? type : 'success';
    els.notice.className = 'qb-notice ' + safeType;
    els.notice.textContent = message;
  }

  function withBusyButton(button, label, task, options) {
    if (typeof withButtonLoading === 'function') {
      return withButtonLoading(button, label, task, options);
    }
    return task();
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

  function openModal(modal) {
    if (modal) modal.classList.add('open');
  }

  function closeModal(modal) {
    if (modal) modal.classList.remove('open');
  }

  function currentUser() {
    return (typeof Auth !== 'undefined' && Auth.getUser) ? Auth.getUser() : null;
  }

  function getTeacherUuid() {
    const user = currentUser() || {};
    return user.teacher_uuid || user.uuid || null;
  }

  function getTeacherCoursesEndpoint(uuid) {
    if (typeof API_ENDPOINTS !== 'undefined' && typeof API_ENDPOINTS.TEACHER_COURSES === 'function') {
      return API_ENDPOINTS.TEACHER_COURSES(uuid);
    }
    return '/teachers/' + uuid + '/courses';
  }

  function toAccessibleImageUrl(rawPath) {
    return typeof resolveQuestionImageUrl === 'function'
      ? resolveQuestionImageUrl(rawPath)
      : String(rawPath || '').trim();
  }

  function courseLabel(course) {
    const subject = course.subject_name || course.course_name || course.name || 'Course';
    const className = course.class_name || '';
    return className ? subject + ' - ' + className : subject;
  }

  function scrollToEditor() {
    if (!els.editorCard || !els.editorCard.scrollIntoView) return;
    els.editorCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
    if (els.questionText) {
      window.setTimeout(function () { els.questionText.focus(); }, 150);
    }
  }

  /* ---------------- Topic / Unit / Week options ---------------- */

  async function loadSectionOptions() {
    const uuid = getTeacherUuid();
    let courses = [];

    if (uuid) {
      try {
        const response = await TeacherAPI.getCourses(uuid, { limit: 500 });
        courses = normalizeRows(response, 'courses');
      } catch (error) {
        console.warn('Failed to load teacher courses for bank organization:', error);
      }
    }

    if (!courses.length && typeof DashboardAPI !== 'undefined' && DashboardAPI.getTeacherStats) {
      try {
        const response = await DashboardAPI.getTeacherStats();
        courses = normalizeRows(response, 'courses');
      } catch (error) {
        console.warn('Failed to load course fallback for bank organization:', error);
      }
    }

    state.teacherCourses = courses;
    const sections = [];

    await Promise.all(courses.map(async function (course) {
      const courseId = Number(course.course_id || course.id || 0);
      if (!courseId) return;

      try {
        const response = await API.get('/courses/' + courseId + '/sections');
        const rows = normalizeRows(response, 'sections');
        rows.forEach(function (section) {
          if (!section || !section.course_sections_id) return;
          const name = String(section.section_name || '').trim();
          if (!name) return;
          sections.push({
            id: Number(section.course_sections_id),
            name: name,
            label: courseLabel(course) + ' – ' + name,
          });
        });
      } catch (error) {
        console.warn('Failed to load course sections for course ' + courseId + ':', error);
      }
    }));

    const seen = new Set();
    state.courseSections = sections.filter(function (section) {
      if (seen.has(section.id)) return false;
      seen.add(section.id);
      return true;
    }).sort(function (a, b) {
      return a.label.localeCompare(b.label);
    });
  }

  function fillSectionSelect(select, placeholder, selected) {
    if (!select) return;

    select.innerHTML = '';
    const first = document.createElement('option');
    first.value = '';
    first.textContent = placeholder;
    select.appendChild(first);

    if (!state.courseSections.length) {
      const empty = document.createElement('option');
      empty.value = '';
      empty.textContent = 'No Topic / Unit / Week available';
      empty.disabled = true;
      select.appendChild(empty);
      return;
    }

    state.courseSections.forEach(function (section) {
      const option = document.createElement('option');
      option.value = String(section.id);
      option.textContent = section.label;
      select.appendChild(option);
    });

    if (selected) select.value = String(selected);
  }

  function populateSectionSelects() {
    const editorSelected = els.courseSection ? els.courseSection.value : '';
    const filterSelected = els.filterCourseSection ? els.filterCourseSection.value : '';
    fillSectionSelect(els.courseSection, 'None', editorSelected);
    fillSectionSelect(els.filterCourseSection, 'All Topic / Unit / Week', filterSelected);
  }

  /* ---------------- Question list ---------------- */

  function buildFilters() {
    return {
      q: state.filters.q,
      course_section_id: state.filters.course_section_id,
      include_archived: state.filters.include_archived ? 1 : null,
      page: state.pagination.current_page,
      limit: state.pagination.per_page,
    };
  }

  function cleanParams(params) {
    const out = {};
    Object.keys(params).forEach(function (key) {
      const value = params[key];
      if (value !== null && value !== undefined && value !== '') out[key] = value;
    });
    return out;
  }

  async function loadQuestions() {
    if (els.listBody) {
      els.listBody.innerHTML = '<tr><td colspan="5" class="qb-empty">Loading questions...</td></tr>';
    }

    try {
      const response = await QuestionBankAPI.getQuestions(cleanParams(buildFilters()));
      const data = response && response.data ? response.data : {};
      state.questions = Array.isArray(data.questions) ? data.questions : [];
      const pagination = data.pagination || {};
      state.pagination = {
        current_page: Number(pagination.current_page) || 1,
        per_page: Number(pagination.per_page) || 25,
        total: Number(pagination.total) || 0,
        total_pages: Number(pagination.total_pages) || 1,
      };
      renderTable();
      renderPagination();
      // Keep any status message set by the action that triggered this reload.
      return true;
    } catch (error) {
      if (els.listBody) {
        els.listBody.innerHTML = '<tr><td colspan="5" class="qb-empty">Failed to load questions.</td></tr>';
      }
      setNotice(error.message || 'Unable to load questions.', 'error');
      return false;
    }
  }

  function courseSectionDisplay(question) {
    if (!question.course_section_id) return '-';
    if (!question.course_section_name) return 'Deleted Topic / Unit / Week';

    const course = [question.course_section_subject_name, question.course_section_class_name]
      .filter(Boolean)
      .join(' - ');

    return course
      ? course + ' – ' + question.course_section_name
      : String(question.course_section_name);
  }

  function renderTable() {
    if (!els.listBody) return;
    if (state.questions.length === 0) {
      els.listBody.innerHTML = '<tr><td colspan="5" class="qb-empty">No questions match your filters.</td></tr>';
      return;
    }

    els.listBody.innerHTML = state.questions.map(function (question) {
      const text = String(question.question_text || '');
      const preview = text.length > 150 ? text.substring(0, 150) + '…' : text;
      const archived = Number(question.is_archived) === 1;

      const actions = [
        '<button class="qb-btn qb-btn-secondary qb-btn-sm" data-action="edit" data-id="' + question.question_id + '">Edit</button>',
        '<button class="qb-btn qb-btn-secondary qb-btn-sm" data-action="archive" data-id="' + question.question_id + '" data-archived="' + archived + '">' + (archived ? 'Restore' : 'Archive') + '</button>',
        '<button class="qb-btn qb-btn-primary qb-btn-sm" data-action="addtoquiz" data-id="' + question.question_id + '">Add to quiz</button>',
      ];

      return '<tr>' +
        '<td class="qb-text-preview" title="' + escapeHtml(text) + '">' + escapeHtml(preview) + '</td>' +
        '<td>' + escapeHtml(typeLabel(question.question_type)) + '</td>' +
        '<td>' + escapeHtml(courseSectionDisplay(question)) + '</td>' +
        '<td>' + escapeHtml(question.points) + '</td>' +
        '<td><div class="qb-row-actions">' + actions.join('') + '</div></td>' +
        '</tr>';
    }).join('');
  }

  function renderPagination() {
    if (!els.pagination) return;
    const pagination = state.pagination;
    if (!pagination.total_pages || pagination.total_pages <= 1) {
      els.pagination.innerHTML = '<span style="font-size:0.8rem;color:#64748b;">' + pagination.total + ' question(s)</span>';
      return;
    }

    let html = '<span style="font-size:0.8rem;color:#64748b;margin-right:0.5rem;">' + pagination.total + ' question(s)</span>';
    html += '<button data-page="' + (pagination.current_page - 1) + '"' + (pagination.current_page <= 1 ? ' disabled' : '') + '>‹</button>';
    for (let page = 1; page <= pagination.total_pages; page++) {
      html += '<button data-page="' + page + '"' + (page === pagination.current_page ? ' class="active"' : '') + '>' + page + '</button>';
    }
    html += '<button data-page="' + (pagination.current_page + 1) + '"' + (pagination.current_page >= pagination.total_pages ? ' disabled' : '') + '>›</button>';
    els.pagination.innerHTML = html;
  }

  /* ---------------- Inline editor ---------------- */

  function updateFormByType() {
    const type = els.questionType ? els.questionType.value : 'multiple_choice';
    const showMultipleChoice = type === 'multiple_choice';
    const showTrueFalse = type === 'true_false';

    if (els.optionsBlock) els.optionsBlock.style.display = showMultipleChoice ? 'grid' : 'none';
    if (els.trueFalseBlock) els.trueFalseBlock.style.display = showTrueFalse ? 'grid' : 'none';
    if (els.correctAnswerField) {
      els.correctAnswerField.style.display = (!showMultipleChoice && !showTrueFalse) ? 'grid' : 'none';
    }
  }

  function setCurrentImage(rawPath) {
    const url = toAccessibleImageUrl(rawPath);
    if (!els.imageQuestionCurrent) return url;
    if (!url) {
      els.imageQuestionCurrent.textContent = '';
      els.imageQuestionCurrent.style.display = 'none';
      return url;
    }
    els.imageQuestionCurrent.textContent = 'Current image: ' + url;
    els.imageQuestionCurrent.style.display = 'block';
    return url;
  }

  function resetEditor(options) {
    const settings = options || {};
    if (els.form) els.form.reset();

    state.editingId = null;
    if (els.editorTitle) {
      els.editorTitle.innerHTML = '<i class="fas fa-plus-circle"></i> New Question';
    }
    if (els.questionId) els.questionId.value = '';
    if (els.questionType) els.questionType.value = 'multiple_choice';
    if (els.points) els.points.value = '1';
    if (els.difficulty) els.difficulty.value = 'medium';
    if (els.correctOption) els.correctOption.value = '';
    if (els.correctAnswer) els.correctAnswer.value = '';
    if (els.trueValue) els.trueValue.checked = false;
    if (els.falseValue) els.falseValue.checked = false;
    ['optionA', 'optionB', 'optionC', 'optionD'].forEach(function (key) {
      if (els[key]) els[key].value = '';
    });
    if (els.imageQuestion) els.imageQuestion.value = '';
    if (els.submitBtn) els.submitBtn.textContent = 'Save Question';

    state.editingImageUrl = setCurrentImage('') || '';
    updateFormByType();
    if (settings.scroll) scrollToEditor();
  }

  async function fillEditor(question) {
    let full = question || {};
    const questionId = Number(full.question_id || full.id || 0);
    setNotice('Opening question...', 'info');

    if (questionId) {
      try {
        const response = await QuestionBankAPI.getQuestion(questionId);
        if (response && response.data) full = response.data;
      } catch (error) {
        // The list row still has enough data to open the editor.
      }
    }

    state.editingId = questionId || null;
    if (els.editorTitle) {
      els.editorTitle.innerHTML = '<i class="fas fa-edit"></i> Edit Question #' + questionId;
    }

    if (els.questionId) els.questionId.value = String(questionId || '');
    if (els.questionText) els.questionText.value = full.question_text || '';
    if (els.questionType) els.questionType.value = full.question_type || 'multiple_choice';
    if (els.points) els.points.value = full.points != null ? full.points : 1;
    if (els.difficulty) els.difficulty.value = full.difficulty || 'medium';
    if (els.explanation) els.explanation.value = full.explanation || '';
    if (els.courseSection) {
      els.courseSection.value = full.course_section_id ? String(full.course_section_id) : '';
    }
    if (els.imageQuestion) els.imageQuestion.value = '';
    state.editingImageUrl = setCurrentImage(full.image_question) || '';

    const optionsByLabel = {};
    let correctFromOptions = '';
    (full.options || []).forEach(function (option) {
      const label = String(option.option_label || option.option_order || '').trim().toUpperCase();
      if (!label) return;
      optionsByLabel[label] = option.option_text || '';
      if (Number(option.is_correct) === 1) correctFromOptions = label;
    });

    if (els.optionA) els.optionA.value = optionsByLabel.A || '';
    if (els.optionB) els.optionB.value = optionsByLabel.B || '';
    if (els.optionC) els.optionC.value = optionsByLabel.C || '';
    if (els.optionD) els.optionD.value = optionsByLabel.D || '';

    const type = els.questionType ? els.questionType.value : 'multiple_choice';
    if (type === 'multiple_choice') {
      if (els.correctOption) {
        els.correctOption.value = String(full.correct_answer || correctFromOptions || '').trim().toUpperCase();
      }
    } else if (type === 'true_false') {
      const answer = String(full.correct_answer || '').toLowerCase();
      if (els.trueValue) els.trueValue.checked = answer === 'true';
      if (els.falseValue) els.falseValue.checked = answer === 'false';
    } else {
      if (els.correctAnswer) els.correctAnswer.value = full.correct_answer || '';
    }

    if (els.submitBtn) els.submitBtn.textContent = 'Update Question';
    updateFormByType();
    setNotice(questionId ? 'Question loaded into editor.' : 'Question loaded.', 'success');
    scrollToEditor();
  }

  function collectPayloadFromForm() {
    const type = els.questionType.value;
    const isMultipleChoice = type === 'multiple_choice';

    const payload = {
      question_text: String(els.questionText.value || '').trim(),
      question_type: type,
      points: Number.parseInt(els.points.value, 10) || 1,
      difficulty: els.difficulty.value,
      explanation: String(els.explanation.value || '').trim() || null,
      course_section_id: Number(els.courseSection && els.courseSection.value) || null,
    };

    if (!payload.question_text) {
      throw new Error('Question text is required.');
    }

    if (isMultipleChoice) {
      const options = [
        { option_order: 'A', option_text: String(els.optionA.value || '').trim() },
        { option_order: 'B', option_text: String(els.optionB.value || '').trim() },
        { option_order: 'C', option_text: String(els.optionC.value || '').trim() },
        { option_order: 'D', option_text: String(els.optionD.value || '').trim() },
      ].filter(function (option) { return option.option_text.length > 0; });

      if (options.length < 2) {
        throw new Error('Multiple choice questions require at least two options.');
      }

      const correctOption = String(els.correctOption.value || '').trim().toUpperCase();
      if (!correctOption) {
        throw new Error('Please select the correct option.');
      }
      if (!options.some(function (option) { return option.option_order === correctOption; })) {
        throw new Error('Correct option must match an entered option.');
      }

      payload.correct_answer = correctOption;
      payload.options = options.map(function (option) {
        return {
          label: option.option_order,
          text: option.option_text,
          is_correct: option.option_order === correctOption ? 1 : 0,
        };
      });
    } else if (type === 'true_false') {
      const answer = els.trueValue && els.trueValue.checked
        ? 'true'
        : (els.falseValue && els.falseValue.checked ? 'false' : '');
      if (!answer) {
        throw new Error('Please select True or False.');
      }
      payload.correct_answer = answer;
      payload.options = [];
    } else {
      const answer = String(els.correctAnswer.value || '').trim();
      if (!answer) {
        throw new Error('Correct answer is required for this question type.');
      }
      payload.correct_answer = answer;
      payload.options = [];
    }

    return payload;
  }

  async function uploadQuestionImage() {
    if (!els.imageQuestion || !els.imageQuestion.files || !els.imageQuestion.files[0]) {
      return null;
    }

    const file = els.imageQuestion.files[0];
    if (file.size > 5 * 1024 * 1024) {
      throw new Error('Question image must be under 5 MB.');
    }

    const formData = new FormData();
    formData.append('file', file);
    formData.append('category', 'quiz-questions');

    const response = await FileAPI.upload(formData);
    const data = response && response.data ? response.data : response;
    const url = data && (data.url || data.api_url) ? String(data.url || data.api_url) : '';
    if (!url) {
      throw new Error('Image uploaded but URL was not returned.');
    }

    return url;
  }

  async function submitQuestion(event) {
    if (event) event.preventDefault();

    const questionId = Number(els.questionId && els.questionId.value) || 0;
    setNotice(questionId ? 'Updating question...' : 'Creating question...', 'info');

    try {
      await withBusyButton(els.submitBtn, questionId ? 'Updating...' : 'Creating...', async function () {
        const payload = collectPayloadFromForm();
        const uploadedUrl = await uploadQuestionImage();

        if (uploadedUrl) {
          payload.image_question = uploadedUrl;
          payload.image_name = uploadedUrl;
        } else if (questionId) {
          const existingUrl = state.editingImageUrl || (function () {
            const existing = state.questions.find(function (item) {
              return Number(item.question_id) === questionId;
            });
            return existing ? toAccessibleImageUrl(existing.image_question) : '';
          })();
          if (existingUrl) {
            payload.image_question = existingUrl;
            payload.image_name = existingUrl;
          }
        }

        if (questionId) {
          await QuestionBankAPI.updateQuestion(questionId, payload);
          showToast('Question updated', 'success');
        } else {
          await QuestionBankAPI.createQuestion(payload);
          showToast('Question created', 'success');
        }

        await loadQuestions();
      });

      resetEditor();
      setNotice(questionId ? 'Question updated.' : 'Question created.', 'success');
    } catch (error) {
      setNotice(error.message || 'Unable to save question.', 'error');
    }
  }

  async function archiveQuestion(id, archived, button) {
    const isArchived = archived === 'true' || archived === true;
    setNotice(isArchived ? 'Restoring question...' : 'Archiving question...', 'info');

    try {
      await withBusyButton(button, isArchived ? 'Restoring...' : 'Archiving...', async function () {
        await QuestionBankAPI.archive(id, isArchived ? false : true);
        showToast(isArchived ? 'Question restored' : 'Question archived', 'success');
        await loadQuestions();
      });
      setNotice(isArchived ? 'Question restored.' : 'Question archived.', 'success');
    } catch (error) {
      setNotice(error.message || 'Unable to update question.', 'error');
    }
  }

  /* ---------------- Add to quiz ---------------- */

  async function loadPickerCourses() {
    if (!els.quizCourse) return;
    els.quizCourse.innerHTML = '<option value="">Loading courses...</option>';
    if (els.quizSelect) els.quizSelect.innerHTML = '<option value="">Select a course first</option>';

    try {
      const uuid = getTeacherUuid();
      let courses = [];
      if (uuid) {
        try {
          const response = await TeacherAPI.getCourses(uuid, { limit: 500 });
          courses = normalizeRows(response, 'courses');
        } catch (error) {
          // Fall through to the dashboard course list.
        }
      }
      if (!courses.length && typeof DashboardAPI !== 'undefined' && DashboardAPI.getTeacherStats) {
        try {
          const response = await DashboardAPI.getTeacherStats();
          courses = normalizeRows(response, 'courses');
        } catch (error) {
          // Keep the empty list.
        }
      }

      state.courses = courses;
      els.quizCourse.innerHTML = '<option value="">Select a course</option>';
      state.courses.forEach(function (course) {
        const option = document.createElement('option');
        option.value = String(course.course_id || course.id);
        option.textContent = courseLabel(course);
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
      const response = await QuizAPI.getByCourse(courseId);
      const quizzes = normalizeRows(response, 'quizzes');
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
    if (els.quizHint) {
      els.quizHint.textContent = 'This links question #' + questionId + ' into the quiz without copying it.';
    }
    if (els.quizCourse) els.quizCourse.value = '';
    if (els.quizSelect) els.quizSelect.innerHTML = '<option value="">Select a course first</option>';
    openModal(els.quizModal);
    loadPickerCourses();
  }

  async function confirmLink() {
    const quizId = els.quizSelect ? Number(els.quizSelect.value) : 0;
    if (!quizId) {
      setNotice('Select a quiz first.', 'error');
      return;
    }

    setNotice('Adding question to quiz...', 'info');
    try {
      await withBusyButton(els.quizConfirm, 'Adding...', async function () {
        await QuizAPI.linkExistingQuestion(quizId, state.pendingQuizQuestionId);
        showToast('Question added to quiz', 'success');
        closeModal(els.quizModal);
        state.pendingQuizQuestionId = null;
      });
      setNotice('Question added to quiz.', 'success');
    } catch (error) {
      setNotice(error.message || 'Unable to add question to quiz.', 'error');
    }
  }

  /* ---------------- CSV import / export ---------------- */

  function renderCsvImportResults(data) {
    if (!els.importResults) return;
    const imported = Number(data.imported || 0);
    const skipped = Number(data.skipped || 0);
    const failed = Number(data.failed || 0);
    const results = Array.isArray(data.results) ? data.results : [];
    const summary = 'Imported ' + imported + ' · Skipped ' + skipped + ' · Failed ' + failed;
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

  async function downloadCsvTemplate(button) {
    setNotice('Preparing CSV template...', 'info');
    try {
      await withBusyButton(button, 'Preparing...', function () {
        return QuestionBankAPI.downloadImportTemplate();
      });
      setNotice('Import template downloaded.', 'success');
    } catch (error) {
      setNotice(error.message || 'Failed to download the import template.', 'error');
    }
  }

  async function exportCsv(button) {
    setNotice('Exporting questions...', 'info');
    try {
      await withBusyButton(button, 'Exporting...', function () {
        return QuestionBankAPI.exportQuestions(state.filters);
      });
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
    setNotice('Importing questions...', 'info');

    try {
      const response = await withBusyButton(els.importConfirm, 'Importing...', function () {
        return QuestionBankAPI.importQuestions(formData);
      });
      const data = response && response.data ? response.data : {};
      const imported = Number(data.imported || 0);
      const skipped = Number(data.skipped || 0);
      const failed = Number(data.failed || 0);

      renderCsvImportResults(data);
      if (imported > 0) await loadQuestions();
      if (els.importFile) els.importFile.value = '';
      setNotice(
        'Imported ' + imported + ', skipped ' + skipped + ', failed ' + failed + '.',
        failed > 0 ? 'error' : 'success'
      );
    } catch (error) {
      renderCsvImportResults({
        imported: 0, skipped: 0, failed: 1,
        results: [{ row: '-', status: 'failed', message: error.message || 'The import request failed.' }]
      });
      setNotice(error.message || 'Failed to import questions.', 'error');
    } finally {
      syncCsvImportButton();
    }
  }

  function bindCsvEvents() {
    els.importBtn?.addEventListener('click', openCsvImportModal);
    els.exportBtn?.addEventListener('click', function () { exportCsv(els.exportBtn); });
    els.templateBtn?.addEventListener('click', function () { downloadCsvTemplate(els.templateBtn); });
    els.importTemplateBtn?.addEventListener('click', function () { downloadCsvTemplate(els.importTemplateBtn); });
    els.importClose?.addEventListener('click', function () { closeModal(els.importModal); });
    els.importCancel?.addEventListener('click', function () { closeModal(els.importModal); });
    els.importConfirm?.addEventListener('click', importCsv);
    els.importFile?.addEventListener('change', syncCsvImportButton);
    els.importModal?.addEventListener('click', function (event) {
      if (event.target === els.importModal) closeModal(els.importModal);
    });
  }

  /* ---------------- Events ---------------- */

  function bindEvents() {
    els.refreshBtn?.addEventListener('click', async function () {
      setNotice('Refreshing questions...', 'info');
      try {
        const refreshed = await withBusyButton(els.refreshBtn, 'Refreshing...', function () { return loadQuestions(); });
        if (refreshed !== false) setNotice('Questions refreshed.', 'success');
      } catch (error) {
        setNotice(error.message || 'Unable to refresh questions.', 'error');
      }
    });
    els.newBtn?.addEventListener('click', function () { resetEditor({ scroll: true }); });
    els.clearBtn?.addEventListener('click', function () { resetEditor({ scroll: false }); });
    els.form?.addEventListener('submit', submitQuestion);
    els.questionType?.addEventListener('change', updateFormByType);

    let searchTimer = null;
    els.search?.addEventListener('input', function () {
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(function () {
        state.filters.q = String(els.search.value || '').trim();
        state.pagination.current_page = 1;
        loadQuestions();
      }, 300);
    });

    els.filterCourseSection?.addEventListener('change', function () {
      state.filters.course_section_id = els.filterCourseSection.value;
      state.pagination.current_page = 1;
      loadQuestions();
    });

    els.includeArchived?.addEventListener('change', function () {
      state.filters.include_archived = els.includeArchived.checked ? 1 : 0;
      state.pagination.current_page = 1;
      loadQuestions();
    });

    els.pagination?.addEventListener('click', function (event) {
      const button = event.target.closest('button[data-page]');
      if (!button || button.disabled) return;
      state.pagination.current_page = Number(button.getAttribute('data-page')) || 1;
      loadQuestions();
    });

    els.listBody?.addEventListener('click', async function (event) {
      const button = event.target.closest('button[data-action]');
      if (!button || button.disabled) return;
      const id = Number(button.getAttribute('data-id'));
      const action = button.getAttribute('data-action');
      const question = state.questions.find(function (item) {
        return Number(item.question_id) === id;
      });
      if (action === 'edit' && question) {
        await withBusyButton(button, 'Opening...', function () { return fillEditor(question); });
      } else if (action === 'archive') {
        await archiveQuestion(id, button.getAttribute('data-archived'), button);
      } else if (action === 'addtoquiz') {
        openQuizModal(id);
      }
    });

    els.quizClose?.addEventListener('click', function () { closeModal(els.quizModal); });
    els.quizCancel?.addEventListener('click', function () { closeModal(els.quizModal); });
    els.quizConfirm?.addEventListener('click', confirmLink);
    els.quizCourse?.addEventListener('change', function () {
      loadPickerQuizzes(els.quizCourse.value);
    });
    els.quizModal?.addEventListener('click', function (event) {
      if (event.target === els.quizModal) closeModal(els.quizModal);
    });
  }

  async function initPage() {
    if (!hasPageRoot()) return;

    cacheElements();
    state.filters = { q: '', course_section_id: '', include_archived: 0 };
    state.pagination.current_page = 1;
    state.editingId = null;

    bindEvents();
    bindCsvEvents();

    await loadSectionOptions();
    populateSectionSelects();
    resetEditor();
    await loadQuestions();
  }

  document.addEventListener('page:loaded', function (event) {
    if (event && event.detail && event.detail.page === 'question-bank') {
      initPage();
    }
  });
})();
