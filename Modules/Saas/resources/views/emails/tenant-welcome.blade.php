@php
    // Inline styles only: email clients strip <style> blocks unpredictably.
    $wrap = 'margin:0;padding:24px 12px;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;';
    $card = 'max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;';
    $pad = 'padding:28px 28px 8px;';
    $muted = 'color:#64748b;font-size:14px;line-height:1.6;margin:0 0 16px;';
    $h = 'margin:0 0 6px;font-size:22px;font-weight:700;color:#0f172a;';
    $btn = 'display:inline-block;background:#ff6b00;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:600;font-size:15px;';
    $label = 'font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;margin:0 0 4px;';
@endphp
<div style="{{ $wrap }}">
  <div style="{{ $card }}">
    <div style="background:#0f172a;padding:22px 28px;">
      <span style="color:#ffffff;font-size:19px;font-weight:700;letter-spacing:-.3px;">NexDine</span>
    </div>

    <div style="{{ $pad }}">
      <h1 style="{{ $h }}">Welcome, {{ $tenant->name }}</h1>
      <p style="{{ $muted }}">
        Your restaurant is live on NexDine. Everything below gets you from here
        to taking your first order.
      </p>

      <p style="margin:0 0 24px;">
        <a href="{{ $adminUrl }}/admin/get-started" style="{{ $btn }}">Start setup</a>
      </p>

      <div style="border-top:1px solid #e2e8f0;padding-top:18px;">
        <p style="{{ $label }}">Your restaurant</p>
        <p style="margin:0 0 14px;font-size:14px;color:#0f172a;line-height:1.7;">
          <strong>{{ $tenant->name }}</strong><br>
          Admin: <a href="{{ $adminUrl }}" style="color:#ff6b00;">{{ $adminUrl }}</a>
          @if($tenant->contact_email)
            <br>Account: {{ $tenant->contact_email }}
          @endif
        </p>

        {{--
          No password is included by design. The owner sets their own via the
          password-reset flow, so a forwarded email never hands over the account.
        --}}
        <p style="margin:0 0 20px;padding:12px 14px;background:#f8fafc;border-radius:8px;font-size:13px;color:#475569;line-height:1.6;">
          Use <strong>Forgot password</strong> on the sign-in page to set your
          password. We never send passwords by email.
        </p>
      </div>

      @if(count($artifacts))
        <div style="border-top:1px solid #e2e8f0;padding-top:18px;">
          <p style="{{ $label }}">Downloads</p>
          <ul style="margin:0 0 18px;padding-left:18px;font-size:14px;color:#0f172a;line-height:1.9;">
            @foreach($artifacts as $artifact)
              <li>
                <a href="{{ $artifact['url'] }}" style="color:#ff6b00;text-decoration:none;">{{ $artifact['name'] }}</a>
                @if(!empty($artifact['version']))
                  <span style="color:#94a3b8;font-size:12px;">v{{ $artifact['version'] }}</span>
                @endif
              </li>
            @endforeach
          </ul>
          <p style="{{ $muted }}">
            All installers, versions and checksums:
            <a href="{{ $adminUrl }}/admin/downloads" style="color:#ff6b00;">Download Center</a>
          </p>
        </div>
      @endif

      <div style="border-top:1px solid #e2e8f0;padding-top:18px;">
        <p style="{{ $label }}">Learn</p>
        <p style="margin:0 0 18px;font-size:14px;line-height:1.9;">
          <a href="{{ $adminUrl }}/admin/documentation" style="color:#ff6b00;text-decoration:none;">Guides &amp; video tutorials</a><br>
          <a href="{{ $adminUrl }}/admin/activation" style="color:#ff6b00;text-decoration:none;">Activate the Waiter App</a>
        </p>
      </div>

      @if(!empty($support['email']) || !empty($support['phone']))
        <div style="border-top:1px solid #e2e8f0;padding-top:18px;">
          <p style="{{ $label }}">Need a hand?</p>
          <p style="margin:0 0 18px;font-size:14px;color:#0f172a;line-height:1.8;">
            @if(!empty($support['email']))
              Email <a href="mailto:{{ $support['email'] }}" style="color:#ff6b00;">{{ $support['email'] }}</a><br>
            @endif
            @if(!empty($support['phone']))
              Call {{ $support['phone'] }}<br>
            @endif
            @if(!empty($support['business_hours']))
              <span style="color:#64748b;font-size:13px;">{{ $support['business_hours'] }}</span>
            @endif
          </p>
        </div>
      @endif
    </div>

    <div style="padding:18px 28px 24px;border-top:1px solid #e2e8f0;">
      <p style="margin:0;font-size:12px;color:#94a3b8;line-height:1.6;">
        You received this because a NexDine restaurant was activated for
        {{ $tenant->name }}.
      </p>
    </div>
  </div>
</div>
