<x-mail::message>
# Welcome to {{ $appName }}, {{ $userName }}!

You have been invited to join **{{ $appName }}** as a **{{ ucfirst(str_replace('_', ' ', $userRole)) }}**.

Your account has been created with the following credentials:

**Email:** {{ $userEmail }}
**Password:** {{ $password }}

<x-mail::button :url="$loginUrl">
Login Now
</x-mail::button>

> For your security, please change your password after your first login.

If you have any questions, feel free to reach out to our support team.

Thanks,<br>
{{ config('app.name') }} Team
</x-mail::message>
