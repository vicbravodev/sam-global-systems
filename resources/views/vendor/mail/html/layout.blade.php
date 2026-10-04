@props([
    'tone' => 'primary',
    'preheader' => null,
])
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="es">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<style>
@media only screen and (max-width: 620px) {
.inner-body, .footer, .header-table { width: 100% !important; }
.content-cell { padding: 28px 22px !important; }
.button { width: 100% !important; text-align: center !important; }
.details-label, .details-value { display: block !important; width: 100% !important; }
.details-label { padding: 10px 16px 2px 16px !important; border-bottom: 0 !important; }
.details-value { padding: 0 16px 10px 16px !important; }
}
</style>
{!! $head ?? '' !!}
</head>
<body>
@if (filled($preheader))
<div class="preheader" style="display: none; max-height: 0; overflow: hidden; mso-hide: all;">{{ $preheader }}&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;</div>
@endif

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="tone-bar tone-bar-{{ $tone }}" height="4" style="font-size: 0; line-height: 0;">&nbsp;</td>
</tr>
<!-- Body content -->
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
