{{-- Currency Settings --}}
<div class="border border-secondary rounded-lg mb-3">
    <h3 class="col-12 page-title mt-3 ">
        {{ __('Currency Settings') }}
    </h3>
    <div class="row my-4 mx-1">

        <div class="form-group col-sm-12 col-md-6">
            <label for="currency_code">{{ __('Currency') }} <span
                    class="text-danger">*</span></label>
            {{-- <select name="currency_code" id="currency_code" class="form-control select2-dropdown select2-hidden-accessible">
                <option value="USD">USD</option>
                <option value="ALL">ALL</option>
                <option value="TZS">TZS</option>
                <option value="UZS">UZS</option>
               
            </select> --}}

            {!! Form::select( 'currency_code', [ 'USD' => 'USD','TZS'=>'TZS' ], $settings['currency_code'] ?? null, ['id' => 'currency_code', 'class' => 'form-control select2-dropdown'], ) !!}


        </div>
        <div class="form-group col-md-6 col-sm-12">
            <label for="currency_symbol">{{ __('currency_symbol') }} <span
                    class="text-danger">*</span></label>
            <input name="currency_symbol" id="currency_symbol"
                value="{{ $settings['currency_symbol'] ?? '' }}" type="text"
                placeholder="{{ __('currency_symbol') }}" class="form-control" required />
        </div>
    </div>
</div>
{{-- End Currency Settings --}}


{{-- Bank transfer --}}
 <div class="border border-secondary rounded-lg mb-3">


    <h3 class="col-12 page-title mt-3 ">
        {{ __('bank_transfer') }}
    </h3>
    <div class="row my-4 mx-1">
        <div class="form-group col-sm-12 col-md-6">
            <label for="bank_transfer_status">{{__("status")}} <span class="text-danger">*</span></label>
            <select name="gateway[bank_transfer][status]" id="bank_transfer_status" class="form-control">
                <option value="0" {{(isset($paymentGateway["bank_transfer"]["status"]) && $paymentGateway["bank_transfer"]["status"]==0) ? 'selected' : ''}}>{{__("Disable")}}</option>
                <option value="1" {{(isset($paymentGateway["bank_transfer"]["status"]) && $paymentGateway["bank_transfer"]["status"]==1) ? 'selected' : ''}}>{{__("Enable")}}</option>         
            </select>
        </div>
        <input type="hidden" name="gateway[bank_transfer][currency_code]" id="bank_transfer_currency" value="{{$paymentGateway["bank_transfer"]['currency_code'] ?? ''}}">

        <div class="form-group col-sm-12 col-md-6">
            <label for="bank_name">{{__("bank_name")}} <span class="text-danger">*</span></label>
            <input type="text" name="gateway[bank_transfer][bank_name]" id="bank_name" class="form-control" placeholder="{{ __('bank_name') }}" required value="{{$paymentGateway["bank_transfer"]['bank_name'] ?? ''}}">
        </div>

        <!-- School Code -->
        <div class="form-group col-sm-12 col-md-6">
            <label for="crdb_school_code">{{ __('School Code') }} <span class="text-danger">*</span></label>
            <input type="text" name="gateway[CRDB][school_code]" id="crdb_school_code"
                class="form-control" placeholder="School Code"
                value="{{ $paymentGateway['CRDB']['school_code'] ?? '' }}">
        </div>

        <div class="form-group col-sm-12 col-md-6">
            <label for="account_name">{{__("account_name")}} <span class="text-danger">*</span></label>
            <input type="text" name="gateway[bank_transfer][account_name]" id="razorpay_secret_key" class="form-control" placeholder="{{ __('account_name') }}" required value="{{$paymentGateway["bank_transfer"]['account_name'] ??''}}">
        </div>

        <div class="form-group col-sm-12 col-md-6">
            <label for="account_no">{{__("account_no")}} <span class="text-danger">*</span></label>
            <input type="text" name="gateway[bank_transfer][account_no]" id="account_no" class="form-control" placeholder="{{ __('account_no') }}" required value="{{$paymentGateway["bank_transfer"]['account_no'] ?? ''}}">
        </div>

       
    </div>
    
</div> 