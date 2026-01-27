(function($){
  'use strict';

  var inflight = false;
  var lastCheck = 0;
  var disabledUntil = 0;

  function getQueryFlag(name){
    try{
      var params = new URLSearchParams(window.location.search || '');
      return params.get(name);
    }catch(e){
      return null;
    }
  }

  function ensureContainer(){
    if(!document.getElementById('ika-achievements-modal')){
      var div = document.createElement('div');
      div.id = 'ika-achievements-modal';
      div.style.display = 'none';
      document.body.appendChild(div);
    }
  }

  function clearPendingAsync(){
    if (!window.IKAAch || !IKAAch.ajaxUrl) return $.Deferred().resolve().promise();
    return $.post(IKAAch.ajaxUrl, {
      action: 'ika_clear_pending_achievements',
      nonce: IKAAch.nonce
    });
  }

  function openDialog(){
    var $m = $('#ika-achievements-modal');
    if(!$m.length) return;

    // If already a dialog, just open.
    if ($m.hasClass('ui-dialog-content')) {
      try { $m.dialog('open'); } catch(e) {}
      return;
    }

    $m.dialog({
      title: 'Achievements Unlocked',
      modal: true,
      width: Math.min(720, Math.max(360, window.innerWidth - 48)),
      draggable: false,
      resizable: false,
      closeOnEscape: true,
      dialogClass: 'ika-ach-dialog',
      close: function(){
        // Prevent immediate re-open loops (e.g., AJAX completes after close).
        disabledUntil = Date.now() + 1500;
      }
    });
  }

  function renderAndOpen(payload){
    if(!payload || !payload.has || !payload.html) return;

    ensureContainer();
    var $m = $('#ika-achievements-modal');

    // IMPORTANT: Clear pending immediately once we decide to show it.
    // This prevents infinite reopen loops if the user closes quickly or AJAX completes trigger checks.
    clearPendingAsync().always(function(){
      $m.html(payload.html).show();
      openDialog();
    });
  }

  function checkPending(reason){
    var now = Date.now();
    if(now < disabledUntil) return;
    if(inflight) return;
    if(now - lastCheck < 800) return;
    lastCheck = now;

    if(!window.IKAAch || !IKAAch.ajaxUrl) return;

    inflight = true;

    var testFlag = getQueryFlag('ika_ach_test');
    $.post(IKAAch.ajaxUrl, {
      action: 'ika_fetch_pending_achievements',
      nonce: IKAAch.nonce,
      reason: reason || '',
      test: testFlag ? '1' : '0'
    })
    .done(function(resp){
      try { if(typeof resp === 'string') resp = JSON.parse(resp); } catch(e) {}
      if(resp && resp.success && resp.data){
        renderAndOpen(resp.data);
      }
    })
    .always(function(){
      inflight = false;
    });
  }

  $(function(){
    ensureContainer();

    // Page load
    checkPending('load');

    // If results are swapped in via AJAX, catch it
    $(document).ajaxComplete(function(evt, xhr, settings){
      // Ignore our own clear/fetch calls to reduce chatter
      if(settings && settings.data && typeof settings.data === 'string'){
        if(settings.data.indexOf('ika_clear_pending_achievements') !== -1) return;
        if(settings.data.indexOf('ika_fetch_pending_achievements') !== -1) return;
      }
      checkPending('ajaxComplete');
    });

    // Backup delayed check
    setTimeout(function(){ checkPending('timeout'); }, 1200);
  });

})(jQuery);
