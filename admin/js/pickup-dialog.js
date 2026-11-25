(function ($) {
	'use strict';

	if (typeof window.assPickup === 'undefined') {
		return;
	}

	var cfg = window.assPickup;
	var t = cfg.i18n;

	var CYRILLIC = {
		'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'ґ': 'g', 'д': 'd', 'е': 'e', 'ё': 'jo',
		'є': 'je', 'ж': 'z', 'з': 'z', 'и': 'i', 'і': 'i', 'ї': 'ji', 'й': 'j', 'к': 'k',
		'л': 'l', 'м': 'm', 'н': 'n', 'о': 'o', 'п': 'p', 'р': 'r', 'с': 's', 'т': 't',
		'у': 'u', 'ў': 'u', 'ф': 'f', 'х': 'ch', 'ц': 'c', 'ч': 'c', 'ш': 's', 'щ': 'sc',
		'ъ': '', 'ы': 'y', 'ь': '', 'э': 'e', 'ю': 'ju', 'я': 'ja'
	};

	var SPECIAL = {
		'–': '-', '—': '-', '‒': '-', '−': '-', '~': '-',
		'‘': "'", '’': "'", '‚': "'", '`': "'",
		'“': '"', '”': '"', '„': '"', '«': '"', '»': '"',
		'…': '...', '€': 'EUR', ' ': ' ',
		'ß': 's', 'æ': 'ae', 'Æ': 'AE', 'ø': 'o', 'Ø': 'O', 'ł': 'l', 'Ł': 'L',
		'đ': 'd', 'Đ': 'D', 'œ': 'oe', 'Œ': 'OE', 'þ': 'th', 'Þ': 'TH', 'ð': 'd', 'Ð': 'D'
	};

	function format(template) {
		var args = Array.prototype.slice.call(arguments, 1);
		var next = 0;
		return String(template).replace(/%(?:(\d+)\$)?[ds]/g, function (match, position) {
			var index = position ? parseInt(position, 10) - 1 : next++;
			return args[index] !== undefined ? String(args[index]) : '';
		});
	}

	function plainText(text) {
		var output = '';
		Array.from(String(text)).forEach(function (character) {
			var lower = character.toLowerCase();
			if (Object.prototype.hasOwnProperty.call(CYRILLIC, lower)) {
				var mapped = CYRILLIC[lower];
				output += lower === character ? mapped : mapped.charAt(0).toUpperCase() + mapped.slice(1);
			} else if (Object.prototype.hasOwnProperty.call(SPECIAL, character)) {
				output += SPECIAL[character];
			} else {
				output += character;
			}
		});
		if (output.normalize) {
			output = output.normalize('NFD').replace(/[̀-ͯ]/g, '');
		}
		return output
			.replace(/[\t\r\n]/g, ' ')
			.replace(/[^\x20-\x7E]/g, '')
			.replace(/[\^{}\\\[\]~|`]/g, '')
			.replace(/ {2,}/g, ' ')
			.trim();
	}

	function renderMessage(template, vars) {
		var rendered = String(template).replace(/\{([a-z_]+)\}/g, function (match, key) {
			return Object.prototype.hasOwnProperty.call(vars, key) ? String(vars[key]) : match;
		});
		return plainText(rendered);
	}

	function partsFor(length) {
		if (length === 0) {
			return 0;
		}
		return length <= cfg.limits.single ? 1 : Math.ceil(length / cfg.limits.part);
	}

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (text !== undefined && text !== null) {
			node.textContent = text;
		}
		return node;
	}

	function Dialog(options) {
		this.mode = options.mode;
		this.ids = options.ids;
		this.resend = !!options.resend && options.ids.length === 1;
		this.deferred = !!options.onConfirm;
		this.onConfirm = options.onConfirm || null;
		this.onCancel = options.onCancel || null;
		this.onFinished = options.onFinished || function () {
			window.location.reload();
		};
		this.busy = false;
		this.finished = false;
		this.data = null;
		this.editors = {};
		this.build();
		this.load();
	}

	Dialog.prototype.build = function () {
		var self = this;

		this.backdrop = el('div', 'ass-modal-backdrop');
		this.modal = el('div', 'ass-modal');
		this.modal.setAttribute('role', 'dialog');
		this.modal.setAttribute('aria-modal', 'true');
		this.modal.setAttribute('aria-labelledby', 'ass-modal-title');

		var header = el('div', 'ass-modal-header');
		this.title = el('h2', '', this.mode === 'sms' ? t.titleSms : (this.mode === 'complete' ? t.titleComplete : t.titleReady));
		this.title.id = 'ass-modal-title';
		this.closeButton = el('button', 'ass-modal-close');
		this.closeButton.type = 'button';
		this.closeButton.setAttribute('aria-label', t.close);
		this.closeButton.appendChild(el('span', 'dashicons dashicons-no-alt'));
		header.appendChild(this.title);
		header.appendChild(this.closeButton);

		this.body = el('div', 'ass-modal-body');
		this.footer = el('div', 'ass-modal-footer');

		this.modal.appendChild(header);
		this.modal.appendChild(this.body);
		this.modal.appendChild(this.footer);
		this.backdrop.appendChild(this.modal);
		document.body.appendChild(this.backdrop);
		document.body.classList.add('ass-modal-open');

		this.closeButton.addEventListener('click', function () {
			self.dismiss();
		});
		this.backdrop.addEventListener('mousedown', function (event) {
			if (event.target === self.backdrop) {
				self.dismiss();
			}
		});
		this.keyHandler = function (event) {
			if (event.key === 'Escape') {
				self.dismiss();
			}
		};
		document.addEventListener('keydown', this.keyHandler);
	};

	Dialog.prototype.destroy = function () {
		document.removeEventListener('keydown', this.keyHandler);
		document.body.classList.remove('ass-modal-open');
		if (this.backdrop.parentNode) {
			this.backdrop.parentNode.removeChild(this.backdrop);
		}
	};

	Dialog.prototype.dismiss = function () {
		if (this.busy) {
			return;
		}
		this.destroy();
		if (this.finished) {
			this.onFinished();
		} else if (this.onCancel) {
			this.onCancel();
		}
	};

	Dialog.prototype.setFooter = function (buttons) {
		var footer = this.footer;
		footer.textContent = '';
		buttons.forEach(function (button) {
			var node = el('button', 'button' + (button.primary ? ' button-primary' : ''), button.label);
			node.type = 'button';
			node.disabled = !!button.disabled;
			node.addEventListener('click', button.onClick);
			if (button.ref) {
				button.ref(node);
			}
			footer.appendChild(node);
		});
	};

	Dialog.prototype.showMessage = function (message, isError) {
		var self = this;
		this.body.textContent = '';
		this.body.appendChild(el('p', isError ? 'ass-modal-error' : 'ass-modal-loading', message));
		this.setFooter([{
			label: t.close,
			onClick: function () {
				self.dismiss();
			}
		}]);
	};

	Dialog.prototype.load = function () {
		var self = this;
		this.showMessage(t.loading, false);

		$.post(cfg.ajaxUrl, {
			action: 'ass_pickup_preview',
			nonce: cfg.nonce,
			mode: this.mode,
			resend: this.resend ? 1 : 0,
			order_ids: this.ids.join(',')
		}).done(function (response) {
			if (!response || !response.success) {
				self.showMessage(response && response.data && response.data.message ? response.data.message : t.requestFailed, true);
				return;
			}
			self.data = response.data;
			self.renderForm();
		}).fail(function () {
			self.showMessage(t.requestFailed, true);
		});
	};

	Dialog.prototype.groupKeys = function () {
		var data = this.data;
		return ['code', 'plain'].filter(function (key) {
			return data.groups[key] && data.groups[key].recipients.length > 0;
		});
	};

	Dialog.prototype.renderForm = function () {
		var self = this;
		var data = this.data;
		var body = this.body;
		var isSmsMode = this.mode === 'sms';
		var isCompleteMode = this.mode === 'complete';
		var noPhone = [];
		var smsRecipients = 0;

		body.textContent = '';
		this.editors = {};

		this.groupKeys().forEach(function (key) {
			data.groups[key].recipients.forEach(function (recipient) {
				if (recipient.phoneOk) {
					smsRecipients++;
				} else {
					noPhone.push('#' + recipient.number + (recipient.email ? ' (' + recipient.email + ')' : ''));
				}
			});
		});

		if (data.eligible.length === 0) {
			body.appendChild(el('p', 'ass-modal-error', t.nothingToDo));
		} else {
			body.appendChild(el('p', 'ass-modal-summary', format(isSmsMode ? t.summarySms : (isCompleteMode ? t.summaryComplete : t.summaryReady), data.eligible.length)));
		}

		if (data.skipped.length > 0) {
			var skippedBox = el('div', 'notice notice-warning inline ass-modal-notice');
			skippedBox.appendChild(el('p', '', format(t.skipped, data.skipped.length)));
			var skippedList = el('ul', 'ass-modal-list');
			data.skipped.slice(0, 20).forEach(function (item) {
				skippedList.appendChild(el('li', '', format(t.orderLine, item.number, item.reason)));
			});
			if (data.skipped.length > 20) {
				skippedList.appendChild(el('li', '', '…'));
			}
			skippedBox.appendChild(skippedList);
			body.appendChild(skippedBox);
		}

		if (data.onHold > 0) {
			var holdBox = el('div', 'notice notice-warning inline ass-modal-notice');
			holdBox.appendChild(el('p', '', format(t.onHold, data.onHold)));
			body.appendChild(holdBox);
		}

		if (data.eligible.length > 0 && !data.configured && !isCompleteMode) {
			var configBox = el('div', 'notice notice-warning inline ass-modal-notice');
			configBox.appendChild(el('p', '', t.notConfigured));
			body.appendChild(configBox);
		}

		if (data.eligible.length > 0 && data.configured && noPhone.length > 0) {
			var phoneBox = el('div', 'notice notice-warning inline ass-modal-notice');
			phoneBox.appendChild(el('p', '', format(t.noPhone, noPhone.length) + ' ' + noPhone.slice(0, 30).join(', ') + (noPhone.length > 30 ? ' …' : '')));
			body.appendChild(phoneBox);
		}

		var canSend = data.configured && smsRecipients > 0;

		if (canSend) {
			this.groupKeys().forEach(function (key) {
				var valid = data.groups[key].recipients.filter(function (recipient) {
					return recipient.phoneOk;
				});
				if (valid.length > 0) {
					body.appendChild(self.renderGroup(key, valid));
				}
			});
		}

		var buttons = [{
			label: t.cancel,
			onClick: function () {
				self.dismiss();
			}
		}];

		if (data.eligible.length > 0) {
			if (!isSmsMode) {
				buttons.push({
					label: isCompleteMode ? t.completeConfirm : t.readyOnly,
					primary: !canSend,
					onClick: function () {
						self.confirm(false);
					}
				});
			}
			if (canSend) {
				buttons.push({
					label: isSmsMode ? t.sendSms : t.readyAndSms,
					primary: true,
					ref: function (node) {
						self.sendButton = node;
					},
					onClick: function () {
						self.confirm(true);
					}
				});
			}
		}

		this.setFooter(buttons);
		this.validate();

		var firstEditor = this.editors[this.groupKeys()[0]];
		if (firstEditor) {
			firstEditor.textarea.focus();
		}
	};

	Dialog.prototype.renderGroup = function (key, recipients) {
		var self = this;
		var group = this.data.groups[key];
		var section = el('div', 'ass-sms-group');
		var heading = el('h3', 'ass-sms-group-title', key === 'code' ? t.groupCode : t.groupPlain);
		heading.appendChild(el('span', 'ass-sms-group-count', format(t.recipients, recipients.length)));
		section.appendChild(heading);

		var columns = el('div', 'ass-sms-columns');
		var editorColumn = el('div', 'ass-sms-editor');
		var previewColumn = el('div', 'ass-sms-preview');

		var textareaId = 'ass-sms-template-' + key;
		var label = el('label', 'ass-sms-label', t.message);
		label.setAttribute('for', textareaId);
		var textarea = el('textarea', 'ass-sms-textarea');
		textarea.id = textareaId;
		textarea.rows = 5;
		textarea.value = group.template;

		var chips = el('div', 'ass-sms-variables');
		chips.appendChild(el('span', 'ass-sms-variables-label', t.insertVariable));
		Object.keys(this.data.variables).forEach(function (name) {
			var chip = el('button', 'button button-small ass-sms-variable', self.data.variables[name]);
			chip.type = 'button';
			chip.title = '{' + name + '}';
			chip.addEventListener('click', function () {
				self.insertVariable(textarea, '{' + name + '}');
			});
			chips.appendChild(chip);
		});

		var counter = el('p', 'ass-sms-counter');
		var warnings = el('div', 'ass-sms-warnings');
		var reset = el('button', 'button-link ass-sms-reset', t.reset);
		reset.type = 'button';
		reset.addEventListener('click', function () {
			textarea.value = group.template;
			self.updateGroup(key);
		});

		editorColumn.appendChild(label);
		editorColumn.appendChild(textarea);
		editorColumn.appendChild(chips);
		editorColumn.appendChild(counter);
		editorColumn.appendChild(warnings);
		editorColumn.appendChild(reset);

		var phone = el('div', 'ass-phone');
		var phoneTop = el('div', 'ass-phone-top');
		phoneTop.appendChild(el('span', 'ass-phone-avatar dashicons dashicons-admin-users'));
		phoneTop.appendChild(el('span', 'ass-phone-sender', cfg.senderName));
		var phoneScreen = el('div', 'ass-phone-screen');
		var bubble = el('div', 'ass-phone-bubble');
		phoneScreen.appendChild(bubble);
		phone.appendChild(phoneTop);
		phone.appendChild(phoneScreen);

		var caption = el('p', 'ass-sms-example');
		var another = el('button', 'button-link ass-sms-another', t.anotherExample);
		another.type = 'button';

		previewColumn.appendChild(el('span', 'ass-sms-label', t.preview));
		previewColumn.appendChild(phone);
		previewColumn.appendChild(caption);
		if (recipients.length > 1) {
			previewColumn.appendChild(another);
		}

		columns.appendChild(editorColumn);
		columns.appendChild(previewColumn);
		section.appendChild(columns);

		this.editors[key] = {
			textarea: textarea,
			counter: counter,
			warnings: warnings,
			bubble: bubble,
			caption: caption,
			recipients: recipients,
			sample: Math.floor(Math.random() * recipients.length),
			valid: true
		};

		textarea.addEventListener('input', function () {
			self.updateGroup(key);
		});
		another.addEventListener('click', function () {
			var editor = self.editors[key];
			editor.sample = (editor.sample + 1 + Math.floor(Math.random() * (recipients.length - 1))) % recipients.length;
			self.updateGroup(key);
		});

		this.updateGroup(key);
		return section;
	};

	Dialog.prototype.insertVariable = function (textarea, token) {
		var start = textarea.selectionStart !== undefined ? textarea.selectionStart : textarea.value.length;
		var end = textarea.selectionEnd !== undefined ? textarea.selectionEnd : textarea.value.length;
		textarea.value = textarea.value.slice(0, start) + token + textarea.value.slice(end);
		textarea.focus();
		textarea.selectionStart = textarea.selectionEnd = start + token.length;
		textarea.dispatchEvent(new Event('input', { bubbles: true }));
	};

	Dialog.prototype.updateGroup = function (key) {
		var editor = this.editors[key];
		var template = editor.textarea.value;
		var variables = this.data.variables;
		var longest = 0;
		var hasEmpty = false;
		var emptyVariables = {};
		var missingArrival = [];
		var unknown = [];
		var used = [];

		template.replace(/\{([a-z_]+)\}/g, function (match, name) {
			if (!Object.prototype.hasOwnProperty.call(variables, name)) {
				if (unknown.indexOf(match) === -1) {
					unknown.push(match);
				}
			} else if (used.indexOf(name) === -1) {
				used.push(name);
			}
			return match;
		});

		editor.recipients.forEach(function (recipient) {
			var text = renderMessage(template, recipient.vars);
			longest = Math.max(longest, text.length);
			if (text.length === 0) {
				hasEmpty = true;
			}
			used.forEach(function (name) {
				if (String(recipient.vars[name] === undefined ? '' : recipient.vars[name]) === '') {
					if (name === 'arrival_time') {
						var point = String(recipient.vars.pickup_point || ('#' + recipient.number));
						if (missingArrival.indexOf(point) === -1) {
							missingArrival.push(point);
						}
						return;
					}
					emptyVariables[name] = (emptyVariables[name] || 0) + 1;
				}
			});
		});

		var sample = editor.recipients[editor.sample];
		var sampleText = renderMessage(template, sample.vars);
		var sampleParts = partsFor(sampleText.length);
		var longestParts = partsFor(longest);

		editor.bubble.textContent = sampleText;
		editor.bubble.classList.toggle('is-empty', sampleText.length === 0);
		editor.caption.textContent = format(t.previewFor, [sample.name, sample.phone, sample.email, '#' + sample.number].filter(Boolean).join(' · '));

		var counterText = sampleParts <= 1
			? format(t.counterSingle, sampleText.length, cfg.limits.single)
			: format(t.counterMulti, sampleText.length, sampleParts);
		if (longest > sampleText.length) {
			counterText += ' ' + format(t.counterLongest, longest, longestParts);
		}
		editor.counter.textContent = counterText;
		editor.counter.classList.toggle('is-over', longestParts > 1);

		editor.warnings.textContent = '';
		var tooLong = longestParts > cfg.limits.maxParts;

		if (hasEmpty) {
			editor.warnings.appendChild(el('p', 'ass-sms-warning is-error', t.emptyMessage));
		}
		if (tooLong) {
			editor.warnings.appendChild(el('p', 'ass-sms-warning is-error', t.tooLong));
		}
		unknown.forEach(function (token) {
			editor.warnings.appendChild(el('p', 'ass-sms-warning is-error', format(t.unknownVariable, token)));
		});
		if (missingArrival.length > 0) {
			editor.warnings.appendChild(el('p', 'ass-sms-warning is-error', format(t.arrivalMissing, missingArrival.join(', '))));
		}
		Object.keys(emptyVariables).forEach(function (name) {
			editor.warnings.appendChild(el('p', 'ass-sms-warning', format(t.emptyVariable, '{' + name + '}', emptyVariables[name])));
		});

		editor.valid = !hasEmpty && !tooLong && unknown.length === 0;
		this.validate();
	};

	Dialog.prototype.validate = function () {
		var editors = this.editors;
		var valid = Object.keys(editors).every(function (key) {
			return editors[key].valid;
		});
		if (this.sendButton) {
			this.sendButton.disabled = !valid;
		}
	};

	Dialog.prototype.templates = function () {
		var editors = this.editors;
		var templates = {};
		Object.keys(editors).forEach(function (key) {
			templates[key] = editors[key].textarea.value;
		});
		return templates;
	};

	Dialog.prototype.confirm = function (sendSms) {
		var templates = this.templates();

		if (this.deferred) {
			var keys = this.groupKeys();
			this.destroy();
			this.onConfirm({
				sendSms: sendSms,
				template: keys.length && templates[keys[0]] !== undefined ? templates[keys[0]] : ''
			});
			return;
		}

		this.process(sendSms, templates);
	};

	Dialog.prototype.process = function (sendSms, templates) {
		var self = this;
		var ids = this.data.eligible.slice();
		var total = ids.length;
		var results = [];
		var chunks = [];

		while (ids.length) {
			chunks.push(ids.splice(0, cfg.chunkSize));
		}

		this.busy = true;
		this.closeButton.disabled = true;
		this.body.textContent = '';
		this.footer.textContent = '';

		var status = el('p', 'ass-modal-progress-text', format(t.progress, 0, total));
		var bar = el('div', 'ass-modal-progress');
		var fill = el('div', 'ass-modal-progress-fill');
		bar.appendChild(fill);
		this.body.appendChild(status);
		this.body.appendChild(bar);

		function next() {
			if (!chunks.length) {
				self.busy = false;
				self.finished = true;
				self.closeButton.disabled = false;
				self.renderResults(results, sendSms);
				return;
			}

			var chunk = chunks.shift();

			$.post(cfg.ajaxUrl, {
				action: 'ass_pickup_process',
				nonce: cfg.nonce,
				mode: self.mode,
				resend: self.resend ? 1 : 0,
				send_sms: sendSms ? 1 : 0,
				bulk: total > 1 ? 1 : 0,
				order_ids: chunk.join(','),
				templates: templates
			}).done(function (response) {
				if (response && response.success && response.data && response.data.results) {
					results = results.concat(response.data.results);
				} else {
					chunk.forEach(function (id) {
						results.push({ id: id, number: String(id), ready: false, sms: '', error: t.requestFailed });
					});
				}
			}).fail(function () {
				chunk.forEach(function (id) {
					results.push({ id: id, number: String(id), ready: false, sms: '', error: t.requestFailed });
				});
			}).always(function () {
				var done = Math.min(total, results.length);
				status.textContent = format(t.progress, done, total);
				fill.style.width = (total ? Math.round(done / total * 100) : 100) + '%';
				next();
			});
		}

		next();
	};

	Dialog.prototype.renderResults = function (results, sendSms) {
		var self = this;
		var ready = 0;
		var completed = 0;
		var sent = 0;
		var failed = 0;
		var noPhone = 0;
		var errors = 0;
		var problems = [];

		results.forEach(function (result) {
			if (result.ready) {
				ready++;
			}
			if (result.completed) {
				completed++;
			}
			if (result.sms === 'sent') {
				sent++;
			} else if (result.sms === 'failed') {
				failed++;
				problems.push(format(t.orderLine, result.number, result.error));
			} else if (result.sms === 'no_phone') {
				noPhone++;
				problems.push(format(t.orderLine, result.number, result.error));
			} else if (result.error) {
				errors++;
				problems.push(format(t.orderLine, result.number, result.error));
			}
		});

		this.title.textContent = t.doneTitle;
		this.body.textContent = '';

		var summary = el('ul', 'ass-modal-results');
		if (this.mode === 'complete') {
			summary.appendChild(el('li', 'is-ok', format(t.doneCompleted, completed)));
		} else if (this.mode !== 'sms') {
			summary.appendChild(el('li', 'is-ok', format(t.doneReady, ready)));
		}
		if (sendSms) {
			summary.appendChild(el('li', 'is-ok', format(t.doneSmsSent, sent)));
			if (failed > 0) {
				summary.appendChild(el('li', 'is-error', format(t.doneSmsFailed, failed)));
			}
			if (noPhone > 0) {
				summary.appendChild(el('li', 'is-warning', format(t.doneNoPhone, noPhone)));
			}
		}
		if (errors > 0) {
			summary.appendChild(el('li', 'is-error', format(t.doneErrors, errors)));
		}
		this.body.appendChild(summary);

		if (problems.length > 0) {
			var list = el('ul', 'ass-modal-list');
			problems.slice(0, 50).forEach(function (line) {
				list.appendChild(el('li', '', line));
			});
			this.body.appendChild(list);
		}

		this.setFooter([{
			label: t.reload,
			primary: true,
			onClick: function () {
				self.dismiss();
			}
		}]);
	};

	function open(options) {
		if (!options.ids || options.ids.length === 0) {
			window.alert(t.noSelection);
			return null;
		}
		return new Dialog(options);
	}

	function checkedValues(selector) {
		return $(selector).filter(':checked').map(function () {
			return parseInt(this.value, 10);
		}).get().filter(function (value) {
			return value > 0;
		});
	}

	function exportPdf(ids, kind) {
		if (!cfg.export) {
			return;
		}
		if (!ids || ids.length === 0) {
			window.alert(t.noSelection);
			return;
		}

		var backdrop = el('div', 'ass-modal-backdrop');
		var modal = el('div', 'ass-modal ass-export-modal');
		modal.setAttribute('role', 'dialog');
		modal.setAttribute('aria-modal', 'true');
		modal.setAttribute('aria-labelledby', 'ass-export-title');

		var header = el('div', 'ass-modal-header');
		var title = el('h2', '', t.exportTitle);
		title.id = 'ass-export-title';
		header.appendChild(title);

		var body = el('div', 'ass-modal-body');
		var status = el('p', 'ass-modal-progress-text', t.exportStarting);
		status.setAttribute('aria-live', 'polite');
		var bar = el('div', 'ass-modal-progress');
		var fill = el('div', 'ass-modal-progress-fill');
		bar.appendChild(fill);
		body.appendChild(status);
		body.appendChild(bar);

		var footer = el('div', 'ass-modal-footer');
		modal.appendChild(header);
		modal.appendChild(body);
		modal.appendChild(footer);
		backdrop.appendChild(modal);
		document.body.appendChild(backdrop);
		document.body.classList.add('ass-modal-open');

		var controller = window.AbortController ? new window.AbortController() : null;
		var running = true;
		var objectUrl = null;
		var fileName = '';
		var loaded = 0;
		var written = 0;
		var total = ids.length;
		var rowsTotal = ids.length;

		function warn(event) {
			event.preventDefault();
			event.returnValue = '';
			return '';
		}

		function stop() {
			running = false;
			window.removeEventListener('beforeunload', warn);
		}

		function close() {
			if (running && controller) {
				controller.abort();
			}
			stop();
			document.removeEventListener('keydown', onKey);
			document.body.classList.remove('ass-modal-open');
			if (backdrop.parentNode) {
				backdrop.parentNode.removeChild(backdrop);
			}
			if (objectUrl) {
				window.URL.revokeObjectURL(objectUrl);
			}
		}

		function onKey(event) {
			if (event.key === 'Escape' && !running) {
				close();
			}
		}

		function addButton(label, primary, onClick) {
			var node = el('button', 'button' + (primary ? ' button-primary' : ''), label);
			node.type = 'button';
			node.addEventListener('click', onClick);
			footer.appendChild(node);
			return node;
		}

		function setProgress() {
			var all = total + rowsTotal;
			fill.style.width = (all ? Math.min(100, Math.round((loaded + written) / all * 100)) : 0) + '%';
		}

		function save() {
			var link = document.createElement('a');
			link.href = objectUrl;
			link.download = fileName;
			link.style.display = 'none';
			document.body.appendChild(link);
			link.click();
			document.body.removeChild(link);
		}

		function fail(message) {
			if (!running) {
				return;
			}
			stop();
			status.textContent = message || t.exportFailed;
			status.className = 'ass-modal-error';
			bar.style.display = 'none';
			footer.textContent = '';
			addButton(t.close, true, close).focus();
		}

		function finish(event) {
			var binary = window.atob(event.data);
			var bytes = new Uint8Array(binary.length);
			for (var i = 0; i < binary.length; i++) {
				bytes[i] = binary.charCodeAt(i);
			}
			objectUrl = window.URL.createObjectURL(new window.Blob([bytes], { type: 'application/pdf' }));
			fileName = event.name;
			stop();
			fill.style.width = '100%';
			status.textContent = format(t.exportDone, event.count);
			footer.textContent = '';
			addButton(t.exportAgain, false, save);
			addButton(t.close, true, close).focus();
			save();
		}

		function handle(line) {
			line = String(line).trim();
			if (!line || !running) {
				return;
			}
			var event;
			try {
				event = JSON.parse(line);
			} catch (error) {
				return;
			}
			if (event.stage === 'orders') {
				loaded = event.done;
				total = event.total;
				status.textContent = format(t.exportLoading, loaded, total);
				setProgress();
			} else if (event.stage === 'pdf') {
				loaded = total;
				written = event.done;
				rowsTotal = event.total;
				status.textContent = format(t.exportWriting, written, rowsTotal);
				setProgress();
			} else if (event.stage === 'error') {
				fail(event.message);
			} else if (event.stage === 'file') {
				finish(event);
			}
		}

		window.addEventListener('beforeunload', warn);
		document.addEventListener('keydown', onKey);
		backdrop.addEventListener('mousedown', function (event) {
			if (event.target === backdrop && !running) {
				close();
			}
		});
		addButton(t.cancel, false, close);

		var filters = cfg.export.filters || {};
		var form = new window.FormData();
		form.append('action', cfg.export.action);
		form.append('nonce', cfg.export.nonce);
		form.append('order_ids', ids.join(','));
		form.append('kind', kind === 'products' ? 'products' : 'orders');
		form.append('shown', String($('input[name="order_ids[]"]').length));
		form.append('pickup_group', filters.group || '');
		form.append('pickup_view', filters.view || '');
		form.append('pickup_date', filters.date || '');
		form.append('pickup_method', filters.method || '');
		(filters.points || []).forEach(function (point) {
			form.append('pickup_points[]', point);
		});
		(filters.cats || []).forEach(function (id) {
			form.append('pickup_cats[]', id);
		});
		(filters.xcats || []).forEach(function (id) {
			form.append('pickup_xcats[]', id);
		});

		window.fetch(cfg.ajaxUrl, {
			method: 'POST',
			body: form,
			credentials: 'same-origin',
			signal: controller ? controller.signal : undefined
		}).then(function (response) {
			if (!response.ok) {
				return response.text().then(function (text) {
					var message = '';
					try {
						message = JSON.parse(text).data.message;
					} catch (error) {
						message = '';
					}
					fail(message);
				});
			}
			if (!response.body || !response.body.getReader || !window.TextDecoder) {
				return response.text().then(function (text) {
					text.split('\n').forEach(handle);
				});
			}

			var reader = response.body.getReader();
			var decoder = new window.TextDecoder();
			var buffer = '';

			function pump() {
				return reader.read().then(function (chunk) {
					if (chunk.done) {
						handle(buffer + decoder.decode());
						return null;
					}
					buffer += decoder.decode(chunk.value, { stream: true });
					var index = buffer.indexOf('\n');
					while (index !== -1) {
						handle(buffer.slice(0, index));
						buffer = buffer.slice(index + 1);
						index = buffer.indexOf('\n');
					}
					return pump();
				});
			}

			return pump();
		}).then(function () {
			fail('');
		}).catch(function () {
			fail(t.requestFailed);
		});
	}

	function selectedBulkAction(button) {
		var which = button.id === 'doaction2' ? 'bottom' : 'top';
		var select = document.getElementById('bulk-action-selector-' + which);
		return select ? select.value : '';
	}

	function initFacets() {
		var node = document.getElementById('ass-pickup-facets');
		var data = null;

		try {
			data = node ? JSON.parse(node.textContent) : null;
		} catch (error) {
			data = null;
		}
		if (!data || !data.rows) {
			return;
		}

		var $date = $('#ass-pickup-date');
		var $method = $('#ass-pickup-method');
		var $cats = $('#ass-pickup-cats');
		var $xcats = $('#ass-pickup-xcats');
		var $points = $('#ass-pickup-points');
		var counts = {};
		var ids = [];

		function values($select) {
			return ($select.val() || []).map(String);
		}

		function fillCategories($select, $other) {
			var selected = values($select);
			var blocked = values($other);

			$select.empty();
			ids.forEach(function (id) {
				if (blocked.indexOf(String(id)) !== -1) {
					return;
				}
				$select.append($('<option>').val(id).text(data.cats[id] + ' (' + counts[id] + ')').prop('selected', selected.indexOf(String(id)) !== -1));
			});
			$select.trigger('change.select2');
		}

		function matching(date, method, points) {
			return data.rows.filter(function (row) {
				return (date === '' || row[0] === date) && (method === '' || row[1] === method) && (!points || points.length === 0 || points.indexOf(row[3]) !== -1);
			});
		}

		function rebuildPoints() {
			if (!$points.length) {
				return;
			}
			var selected = values($points);
			var pointCounts = {};

			matching($date.val() || '', '').forEach(function (row) {
				pointCounts[row[3]] = (pointCounts[row[3]] || 0) + 1;
			});
			selected.forEach(function (point) {
				if (!Object.prototype.hasOwnProperty.call(pointCounts, point)) {
					pointCounts[point] = 0;
				}
			});

			$points.empty();
			Object.keys(pointCounts).sort(function (a, b) {
				return a.localeCompare(b);
			}).forEach(function (point) {
				$points.append($('<option>').val(point).text((point !== '' ? point : '–') + ' (' + pointCounts[point] + ')').prop('selected', selected.indexOf(point) !== -1));
			});
			$points.trigger('change.select2');
		}

		function rebuildMethods() {
			var current = $method.val() || '';
			var counts = {};
			var order = [];

			matching($date.val() || '', '').forEach(function (row) {
				if (!Object.prototype.hasOwnProperty.call(counts, row[1])) {
					counts[row[1]] = 0;
					order.push(row[1]);
				}
				counts[row[1]]++;
			});

			$method.find('option').slice(1).remove();
			order.forEach(function (id) {
				$method.append($('<option>').val(id).text(data.methods[id] + ' (' + counts[id] + ')'));
			});
			$method.val(Object.prototype.hasOwnProperty.call(counts, current) ? current : '');
		}

		function rebuildCategories() {
			counts = {};

			matching($date.val() || '', $method.val() || '', values($points)).forEach(function (row) {
				row[2].forEach(function (id) {
					counts[id] = (counts[id] || 0) + 1;
				});
			});

			var kept = values($cats).concat(values($xcats));
			kept.forEach(function (id) {
				if (!Object.prototype.hasOwnProperty.call(counts, id) && Object.prototype.hasOwnProperty.call(data.cats, id)) {
					counts[id] = 0;
				}
			});

			ids = Object.keys(counts).sort(function (a, b) {
				return String(data.cats[a]).localeCompare(String(data.cats[b]));
			});

			fillCategories($cats, $xcats);
			fillCategories($xcats, $cats);
		}

		$date.on('change', function () {
			rebuildMethods();
			rebuildPoints();
			rebuildCategories();
		});
		$points.on('change', rebuildCategories);
		$method.on('change', rebuildCategories);
		$cats.on('change', function () {
			fillCategories($xcats, $cats);
		});
		$xcats.on('change', function () {
			fillCategories($cats, $xcats);
		});

		rebuildCategories();
	}

	function initPickupPage() {
		document.addEventListener('click', function (event) {
			var button = event.target.closest ? event.target.closest('#doaction, #doaction2') : null;
			if (!button) {
				return;
			}
			var action = selectedBulkAction(button);
			var modes = { ass_mark_ready: 'ready', ass_mark_completed: 'complete', ass_send_sms: 'sms' };
			if (action.indexOf('multiparcels_') === 0) {
				if (checkedValues('input[name="order_ids[]"]').length === 0) {
					event.preventDefault();
					event.stopImmediatePropagation();
					window.alert(t.noSelection);
				}
				return;
			}
			event.preventDefault();
			event.stopImmediatePropagation();
			if (action === 'ass_export_pdf' || action === 'ass_export_products_pdf') {
				exportPdf(checkedValues('input[name="order_ids[]"]'), action === 'ass_export_products_pdf' ? 'products' : 'orders');
				return;
			}
			if (!Object.prototype.hasOwnProperty.call(modes, action)) {
				return;
			}
			open({
				mode: modes[action],
				ids: checkedValues('input[name="order_ids[]"]')
			});
		}, true);

		$(document).on('click', '.ass-pickup-row-action', function (event) {
			event.preventDefault();
			open({
				mode: $(this).data('mode') === 'sms' ? 'sms' : 'ready',
				resend: String($(this).data('resend')) === '1',
				ids: [parseInt($(this).data('order-id'), 10)]
			});
		});

		$('#ass-pickup-filter-submit').on('click', function () {
			$('#ass-pickup-orders-form input[name="paged"]').val(1);
		});

		initFacets();
	}

	function initOrdersList() {
		document.addEventListener('click', function (event) {
			var button = event.target.closest ? event.target.closest('#doaction, #doaction2') : null;
			if (!button || selectedBulkAction(button) !== cfg.bulkAction) {
				return;
			}
			event.preventDefault();
			event.stopImmediatePropagation();
			open({
				mode: 'ready',
				ids: checkedValues('input[name="id[]"], input[name="post[]"]')
			});
		}, true);
	}

	function initOrderEdit() {
		if (!cfg.order) {
			return;
		}

		$(document).on('click', '.ass-pickup-send-sms', function (event) {
			event.preventDefault();
			open({
				mode: 'sms',
				resend: String($(this).data('resend')) === '1',
				ids: [cfg.order.id]
			});
		});

		var statusField = document.getElementById('order_status');
		var form = statusField ? statusField.form : null;
		if (!form) {
			return;
		}

		var confirmed = false;
		var submitter = null;

		form.addEventListener('click', function (event) {
			var target = event.target.closest ? event.target.closest('button[type="submit"], input[type="submit"]') : null;
			if (target) {
				submitter = target;
			}
		}, true);

		function setHidden(name, value) {
			var field = form.querySelector('input[type="hidden"][name="' + name + '"]');
			if (!field) {
				field = document.createElement('input');
				field.type = 'hidden';
				field.name = name;
				form.appendChild(field);
			}
			field.value = value;
		}

		function resubmit() {
			confirmed = true;
			if (submitter && typeof form.requestSubmit === 'function' && form.contains(submitter)) {
				form.requestSubmit(submitter);
			} else if (submitter && form.contains(submitter)) {
				submitter.click();
			} else {
				form.submit();
			}
		}

		form.addEventListener('submit', function (event) {
			if (confirmed || statusField.value !== cfg.readyStatus || cfg.order.status === cfg.readyStatus) {
				return;
			}
			if (submitter && /delete|trash/i.test(String(submitter.name || ''))) {
				return;
			}

			event.preventDefault();
			event.stopImmediatePropagation();

			open({
				mode: 'edit',
				ids: [cfg.order.id],
				onConfirm: function (choice) {
					if (choice.sendSms) {
						setHidden('ass_pickup_sms_send', '1');
						setHidden('ass_pickup_sms_template', choice.template);
						setHidden('ass_pickup_sms_nonce', cfg.order.saveNonce);
					} else {
						setHidden('ass_pickup_sms_send', '');
					}
					resubmit();
				},
				onCancel: function () {
					$(form).find('.save_order').prop('disabled', false).removeClass('disabled');
					$(form).find('.spinner').removeClass('is-active');
				}
			});
		}, true);
	}

	$(function () {
		if (cfg.context === 'pickup_page') {
			initPickupPage();
		} else if (cfg.context === 'orders_list') {
			initOrdersList();
		} else if (cfg.context === 'order_edit') {
			initOrderEdit();
		}
	});
})(jQuery);
