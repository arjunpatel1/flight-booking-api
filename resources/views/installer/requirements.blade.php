@extends('installer.layout')

@section('content')
    <div class="requirements-container">
        <!-- Title -->
        <h2 class="title">System Requirements</h2>
        <p class="subtitle">We are checking your server configuration:</p>

        <!-- Requirements Grid -->
        <div class="requirements-list grid scrollable">
            @foreach ($requirements as $key => $ok)
                <div class="requirement-item">
                    <span
                        class="requirement-name">{{ ucfirst($key) }} {{in_array( $key,$listNotExtensions) ?'':'Extension'}}</span>
                    <span class="requirement-status {{ $ok ? 'ok' : 'fail' }}">
                    {{ $ok ? '✔ OK' : '❌ Fail' }}
                </span>
                </div>
            @endforeach
        </div>

        <div class="nav-buttons">
            <a href="{{ route('installer.welcome') }}" class="btn-secondary">← Back</a>

            <a href="{{ route('installer.permissions') }}"
               class="btn-primary {{ in_array(false, $requirements, true) ? 'disabled' : '' }}">
                Next →
            </a>
        </div>
    </div>
@endsection
