@extends('installer.layout', ['currentStep' => 'permissions'])

@section('content')
    <div class="permissions-container">
        <h2 class="title">Permissions Check</h2>
        <p class="subtitle">We’re checking if the required files and folders are writable:</p>

        <ul class="permissions-list">
            @foreach ($permissions as $path => $isWritable)
                <li class="{{ $isWritable ? 'ok' : 'fail' }}">
                <span class="icon">
                    {!! $isWritable ? '✔' : '❌' !!}
                </span>
                    <div class="text">
                        <strong>{{ basename($path) }}</strong>
                        <small>{{ $path }}</small>
                    </div>
                    <span class="status">
                    {{ $isWritable ? 'Writable' : 'Not Writable' }}
                </span>
                </li>
            @endforeach
        </ul>

        <div class="nav-buttons">
            <a href="{{ route('installer.requirements') }}" class="btn-secondary">← Back</a>
            <a href="{{ route('installer.database') }}"
               class="btn-primary {{ in_array(false, $permissions, true) ? 'disabled' : '' }}">
                Next →
            </a>
        </div>
    </div>
@endsection
