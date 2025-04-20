    public function account_payment(){
        
        
        $user = auth()->user();
        
        
        $apiKey =  app('tzsmmpay_api_key');
        $url = 'https://tzsmmpay.com/api/payment/create';
    
        $paymentData = [
            'api_key' => $apiKey,
            'cus_name' =>  auth()->user()->fname ." ". auth()->user()->lname,
            'cus_email' =>  'demo@gmail.com',
            'cus_number' => $user->id,
            'amount' => app('account_price'),
            'currency' => 'BDT',
            'success_url' => route('dashboard'),
            'cancel_url' => route('account_payment_status', '0'),
            'callback_url' => route('tzsmmpayCallback',$user->id),
        ];
    
        $options = [
            'http' => [
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($paymentData),
            ],
        ];
    
        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);
    
        // Decode the JSON response
        $responseData = json_decode($response, true);
    
        if ($responseData && $responseData['success']) {
            return redirect($responseData['payment_url']);
        } else {
            return  $responseData['messages'] ?? 'An error occurred.';
        }
        
    }
    
    public function tzsmmpayCallback(Request $request, $user_id) {
        $validator = \Validator::make($request->all(), [
            'amount' => 'required|numeric',
            'cus_name' => 'required',
            'cus_email' => 'required|email',
            'cus_number' => 'required',
            'trx_id' => 'required',
            'status' => 'required',
            'extra' => 'nullable|array',
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => implode(', ', $validator->errors()->all()),
            ]);
        }
    
        $trx_id = $request->trx_id;
        $amount = $request->amount;
        $p_type = 'TZSMM Pay'; 
        $transaction_id = $trx_id;
    
        $old_check = Payment::where('trxid', $trx_id)->first();
        if ($old_check) {
            return 'TRX ID ALLREADY USED';
        }
    
        $api_key = app('tzsmmpay_api_key');
        $url = "https://tzsmmpay.com/api/payment/verify?api_key={$api_key}&trx_id={$trx_id}";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    
        if ($response === false || $http_status !== 200) {
            echo json_encode(['status' => 'error', 'message' => 'Error verifying transaction.']);
            exit;
        }
    
        $result = json_decode($response, true);
    
        if (isset($result['status']) && $result['status'] == 'Completed') {
            $active_user = User::where('id', $user_id)->update(['user_active' => '1', 'job_limit' => '1']);
            $payment_add = Payment::create([
                'user_id' => $user_id,
                'method' => $p_type,
                'number' => $transaction_id,
                'trxid' => $transaction_id,
                'amount' => $amount,
                'status' => 'Success',
            ]);
    
            if ($active_user && $payment_add) {
                $user = User::find($user_id);
                $user->balance = $user->balance + 0;
                $user->save();
                $this->handleReferralCommissions($user, $amount, 1);
                return 'Payment Success Acoount active now';
            } else {
                return 'Payment failed';
            }
        }
    }
