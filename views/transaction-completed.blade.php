@extends(config('laravel-cashier.extended_layout'))
@section('title', 'Transaction Summary')

@section('content')

<style>
	@media print {

		.header,
		.footer,
		.page-titles,
		.left-sidebar {
			display: none;
		}
	}
</style>
<div class="p-10 rounded">
	@if ( !is_null( $payment ) )

	Dear <b>{{ $payment->user->name }}</b>, your transaction with reference number <code>{{ $payment->transaction_reference }}</code>

	@if ($payment->is_success == 1)

	was successful.

	@if ($isJsonDescription)
	@include('laravel-cashier::partials.payment-summary-json')
	@else
	@include('laravel-cashier::partials.payment-summary-generic')
	@endif


	<div class="mt-8">
		<button class="px-8 py-2 text-white bg-green-500 rounded" type="button" onclick="print()">Print</button>
	</div>

	@else

	was not successful.

	<div class="mt-8">
		Reason:
		<pre>
			{{ $payment->processor_returned_response_description }}
		</pre>

	</div>

	@endif

	@if ($payment->completion_url)

	<a class="px-8 py-2 text-white bg-blue-800 main-btn" href="{{ $payment->completion_url }}">
		Click here to continue
	</a>

	@endif


	@else

	<div class="container">

		Error: could not process transaction response.

	</div>

	@endif
</div>
@endsection