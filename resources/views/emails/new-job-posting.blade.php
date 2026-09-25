{{-- resources/views/emails/new-job-posting.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Job Opportunity - PhilCST Alumni</title>
</head>
<body style="margin:0;padding:0;background-color:#f3f0f7;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f0f7;padding:30px 16px;">
    <tr>
        <td align="center">
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:580px;background-color:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 8px 32px rgba(122,63,145,0.13);">

                {{-- HEADER --}}
                <tr>
                    <td style="background:linear-gradient(135deg,#7a3f91 0%,#5a2d6f 100%);padding:40px 32px 36px;text-align:center;">
                        <h1 style="margin:0 0 6px;font-size:22px;font-weight:700;color:#ffffff;letter-spacing:-0.3px;">New Job Opportunity</h1>
                        <p style="margin:0;font-size:13px;color:rgba(255,255,255,0.82);font-weight:400;">Philippine College of Science and Technology</p>
                    </td>
                </tr>

                {{-- BODY --}}
                <tr>
                    <td style="padding:36px 32px 0;">
                        <p style="margin:0 0 8px;font-size:16px;color:#333;line-height:1.6;">
                            Hello <strong style="color:#7a3f91;">{{ $alumni->first_name }}</strong>,
                        </p>
                        <p style="margin:0 0 28px;font-size:14px;color:#666;line-height:1.75;">
                            A new job opportunity matching your program has just been posted. Here's a quick preview — visit the portal for full details and to apply.
                        </p>

                        {{-- JOB TEASER BOX --}}
                        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f9f5ff;border:2px dashed #c4b5fd;border-radius:12px;margin-bottom:24px;">
                            <tr>
                                <td style="padding:24px;">

                                    {{-- Label: Job Title --}}
                                    <span style="display:block;font-size:11px;font-weight:700;color:#7a3f91;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:10px;">Job Title</span>

                                    {{-- Value: big bold title --}}
                                    <p style="margin:0 0 14px;font-size:22px;font-weight:700;color:#1f2937;line-height:1.3;">{{ $job->job_title }}</p>

                                    {{-- Meta row: Company · Location · Employment Type (values only, no labels) --}}
                                    <p style="margin:0 0 18px;font-size:13px;color:#6b7280;line-height:1.6;">
                                        @if($job->company_name)
                                            <span style="color:#374151;font-weight:600;">{{ $job->company_name }}</span>
                                        @endif
                                        @if($job->location)
                                            @if($job->company_name)<span style="color:#c4b5fd;margin:0 6px;">&bull;</span>@endif
                                            <span>{{ $job->location }}</span>
                                        @endif
                                        @if($job->employment_type)
                                            @if($job->company_name || $job->location)<span style="color:#c4b5fd;margin:0 6px;">&bull;</span>@endif
                                            <span style="display:inline-block;background-color:#ede9fe;color:#6d28d9;font-size:11px;font-weight:700;padding:2px 9px;border-radius:999px;vertical-align:middle;">{{ $job->employment_type }}</span>
                                        @endif
                                    </p>

                                    {{-- Deadline row (label + value) --}}
                                    @if($job->deadline)
                                    <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                        <tr>
                                            <td style="padding:7px 0;font-size:13px;color:#7a3f91;font-weight:700;width:130px;vertical-align:top;">Deadline</td>
                                            <td style="padding:7px 0;font-size:14px;color:#333;">{{ \Illuminate\Support\Carbon::parse($job->deadline)->format('M d, Y') }}</td>
                                        </tr>
                                    </table>
                                    @endif

                                </td>
                            </tr>
                        </table>

                        {{-- HOW TO USE --}}
                        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f5f3ff;border-left:3px solid #7a3f91;border-radius:0 8px 8px 0;margin-bottom:24px;">
                            <tr>
                                <td style="padding:14px 16px;">
                                    <p style="margin:0 0 4px;font-size:13px;font-weight:700;color:#7a3f91;">Full details are on the portal</p>
                                    <p style="margin:0;font-size:13px;color:#555;line-height:1.65;">
                                        Description, qualifications, salary, and how to apply are available once you open the post on the PhilCST Alumni Connect portal.
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 36px;font-size:14px;color:#666;line-height:1.7;">
                            You're receiving this because this posting matches your program. You can review it anytime from the Job Opportunities page.
                        </p>
                    </td>
                </tr>

                {{-- FOOTER --}}
                <tr>
                    <td style="background-color:#f9f5ff;border-top:1px solid #ede9fe;padding:24px 32px;text-align:center;">
                        <p style="margin:0 0 4px;font-size:13px;color:#7c3aed;font-weight:600;">PhilCST Alumni Connect</p>
                        <p style="margin:0 0 4px;font-size:12px;color:#a78bda;">Philippine College of Science and Technology</p>
                        <p style="margin:14px 0 0;font-size:11.5px;color:#c4b5fd;">
                            © {{ date('Y') }} PhilCST Alumni Connect. All rights reserved.<br>
                            This is an automated message. Please do not reply.
                        </p>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>

</body>
</html>