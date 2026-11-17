/* Copyright (C) 2026 Facilo <info@facilo.es> - GPL v3 or later
 * Factura Pública: DIR3 finder panel and "copy" buttons. Needs jQuery, which Dolibarr loads. */
jQuery(function ($) {
	$(document).on('click', '[data-fp-copy]', function (e) {
		e.preventDefault();
		var b = $(this), text = $(b.data('fp-copy')).text();
		var done = function () { b.text(b.data('fp-done')); };
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(done);
		} else {
			var t = $('<textarea>').val(text).appendTo('body').select();
			document.execCommand('copy');
			t.remove();
			done();
		}
	});

	// The exemption reason only matters on exempt lines: hide it in view mode while it's empty
	$('[id^="extrarow-facturedet_fp_exempt_reason_"]').each(function () {
		var v = $(this).find('.valuefieldcreate');
		if (!v.find('input, select, textarea').length && !$.trim(v.text())) {
			$(this).hide();
		}
	});

	var pan = $('#fp-finder'), back = $('#fp-back');
	if (!pan.length) {
		return;
	}
	var res = pan.find('.fpc-res'), q = pan.find('.fp-q'), save = pan.find('button[type=submit]');
	var roles = pan.data('roles'), timer = null, last = '';

	function open() {
		pan.removeAttr('hidden');
		back.removeAttr('hidden');
		q.trigger('focus');
		if (q.val().length >= 2) {
			search(q.val());
		}
	}
	function close() {
		pan.attr('hidden', '');
		back.attr('hidden', '');
	}
	function note(text) {
		res.empty().append($('<p class="fpc-note">').text(text));
	}
	function search(text) {
		if (text === last) {
			return;
		}
		last = text;
		note(pan.data('searching'));
		$.getJSON(pan.data('url'), { q: text }, function (data) {
			if (text !== last) {
				return;
			}
			res.empty();
			var n = 0;
			$.each(data.results || [], function (i, a) {
				$.each(a.units || [], function (j, u) {
					var codes = [[u.oc_code, u.oc_name], [u.og_code, u.og_name], [u.ut_code, u.ut_name]];
					var r = $('<button type="button" class="fpc-r">').data('codes', codes);
					r.append($('<b>').text(a.name));
					var dl = $('<dl>');
					$.each(codes, function (k, c) {
						dl.append($('<dt>').text(roles[k]), $('<dd>').append($('<code>').text(c[0]), document.createTextNode(c[1] || '')));
					});
					res.append(r.append(dl));
					n++;
				});
			});
			if (!n) {
				note(pan.data('none'));
			}
		}).fail(function () {
			note(pan.data('none'));
		});
	}

	$(document).on('click', '[data-fp-finder]', function (e) {
		e.preventDefault();
		open();
	});
	back.on('click', close);
	// A link ending in #fp-finder opens the finder straight away
	if (window.location.hash === '#fp-finder') {
		open();
	}
	pan.on('click', '.fpc-x', close);
	$(document).on('keydown', function (e) {
		if (e.key === 'Escape' && !pan.is('[hidden]')) {
			close();
		}
	});
	q.on('input', function () {
		clearTimeout(timer);
		var v = $.trim(q.val());
		timer = setTimeout(function () {
			if (v.length >= 2) {
				search(v);
			}
		}, 300);
	});
	q.on('keydown', function (e) {
		if (e.key === 'Enter') {
			e.preventDefault();
		}
	});
	res.on('click', '.fpc-r', function () {
		res.find('.fpc-r').removeClass('on');
		var c = $(this).addClass('on').data('codes');
		pan.find('input[name=dir3_oc]').val(c[0][0]);
		pan.find('input[name=dir3_og]').val(c[1][0]);
		pan.find('input[name=dir3_ut]').val(c[2][0]);
		save.prop('disabled', false);
	});
});
