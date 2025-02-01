<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

use App\Models\GamesHistory;
use Illuminate\Support\Facades\DB;
use App\Models\UserPlayer;
use App\Models\AgentApi;
use App\Models\GameList;
use App\Models\ProviderList;
use Illuminate\Support\Str;

use App\Models\ApiActive;
use App\Models\ApiProvider;
use App\Http\Controllers\Callback\CallbackController;

use Yajra\DataTables\Facades\DataTables;

class ApiController extends Controller
{

    public function methode(Request $request)
    {

        if ($_SERVER['REQUEST_METHOD'] != 'POST') {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_METHOD'
            ], 200);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $method = $data['method'];

        if (!$data['agent_code']) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_PARAMETER'
            ], 200);
        } elseif (!$data['agent_token']) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_PARAMETER'
            ], 200);
        }
        $agentapi = DB::table('agents')->where('agentCode', $data['agent_code'])->first();
        if (!$agentapi) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_AGENT'
            ], 200);
        } elseif ($data['agent_token'] !== $agentapi->token) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_AGENT_TOKEN'
            ], 200);
        } elseif ($agentapi->status !== 1) {
            return response()->json([
                'status' => 0,
                'msg' => 'BLOCKED_AGENT'
            ], 200);
        }

        switch ($method) {
            case 'user_create':
                return $this->PlayerAccountCreate($data);
                break;
            case 'money_info':
                return $this->getBalance($data);
                break;
            case 'user_deposit':
                return $this->balanceTopup($data);
                break;
            case 'user_withdraw':
                return $this->balanceWithdraw($data);
                break;
            case 'get_game_log':
                return $this->getHistory($data);
                break;
            case 'get_history':
                return $this->getHistory2($data);
                break;
            case 'game_launch':
                return $this->launch_game($data);
                break;
            case 'provider_list':
                return $this->provider_list($data);
                break;
            case 'user_withdraw_reset':
                return $this->balanceWithdrawAll($data);
                break;
            case 'game_list':
                return $this->game_list($data);
                break;
            case 'control_rtp':
                return $this->control_rtp($data);
                break;
            default:
                abort(404);
        }
    }

    function PlayerAccountCreate($data)
    {
        $agentscs = DB::table('agents')->where('agentCode', $data['agent_code'])->first();

        if ($agentscs->apiType != 1) {
            return response()->json([
                'status' => 0,
                'msg' => 'AGENT_SEAMLESS'
            ], 200);
        }

        if (!$data['user_code']) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_PARAMETER'
            ], 200);
        }

        $player = DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->where('apiType', 1)->first();

        if (!empty($player)) {
            return response()->json([
                'status' => 0,
                'msg' => 'DUPLICATED_USER'
            ], 200);
        }

        DB::table('users')->insert([
            'agentCode' => $data['agent_code'],
            'userCode' => $data['user_code'],
            'targetRtp' => 80,
            'realRtp' => 80,
            'balance' => 0,
            'aasUserCode' => $data['user_code'],
            'status' => 1,
            'parentPath' => 1,
            'totalDebit' => 0,
            'totalCredit' => 0,
            'apiType' => 1,
            'updatedAt' => date("Y-m-d H:i:s"),
            'createdAt' => date("Y-m-d H:i:s")
        ]);

        return response()->json([
            'status' => 1,
            'msg' => 'SUCCESS',
            'user_code' => $data['user_code'],
            'user_balance' => 0
        ], 200);
    }

    function getHistory($data)
    {
        $postArray = [
            'method' => 'get_history',
            'agent_code' => $data['agent_code'],
            'agent_token' => $data['agent_token'],
            'user_code' => $data['user_code'],
            'start' => $data['start'],
            'end' => $data['end'],
            'page' => $data['page'],
            'perPage' => $data['perPage'],
        ];
        $jsonData = json_encode($postArray);


        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://1api.isomatslot.com/',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json'
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);

        $result = json_decode($response);

        return response()->json([
            'status' => 1,
            'total_count' => $result->total_count,
            'current_page' => $result->data->current_page,
            'total_page' => $result->data->last_page,
            'per_page' => $result->data->per_page,
            'data' => $result->data->data
        ], 200);
    }

    function getHistory2($data)
    {
        $games = DB::table('trans_bet')->where('agent_code', $data['agent_code'])->where('user_code', $data['user_code'])->select(['history_id', 'agent_code', 'user_code', 'game_code', 'type', 'bet_money', 'win_money', 'txn_id', 'txn_type', 'user_start_balance', 'user_end_balance', 'agent_start_balance', 'agent_end_balance', 'created_at'])->paginate($data['perPage']);
        $count = DB::table('trans_bet')->where('agent_code', $data['agent_code'])->where('user_code', $data['user_code'])->count();
        return response()->json([
            'status' => 1,
            'total_count' => $count,
            'data' => $games
        ], 200);
    }

    function getBalance($data)
    {

        $agents = DB::table('agents')->where('agentCode', $data['agent_code'])->first();

        if (!isset($data['user_code'])) {
            return response()->json([
                'status' => 1,
                'msg' => 'SUCCESS',
                'agent' => [
                    'agent_code' => $data['agent_code'],
                    'balance' => $agents->balance
                ]
            ], 200);
        }

        $player = DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->where('apiType', 1)->first();

        if (!$player) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_USER'
            ], 200);
        }

        $agentscs = DB::table('agents')->where('agentCode', $data['agent_code'])->first();

        if ($agentscs->apiType != 1) {
            return response()->json([
                'status' => 1,
                'msg' => 'SUCCESS',
                'agent' => [
                    'agent_code' => $data['agent_code'],
                    'balance' => $agents->balance
                ]
            ], 200);
        } else {
            return response()->json([
                'status' => 1,
                'msg' => 'SUCCESS',
                'agent' => [
                    'agent_code' => $data['agent_code'],
                    'balance' => $agents->balance
                ],
                'user' => [
                    'user_code' => $data['user_code'],
                    'balance' => $player->balance
                ]
            ], 200);
        }
    }

    function balanceTopup($data)
    {
        $agentscs = DB::table('agents')->where('agentCode', $data['agent_code'])->first();

        if ($agentscs->apiType != 1) {
            return response()->json([
                'status' => 0,
                'msg' => 'AGENT_SEAMLESS'
            ], 200);
        }

        if (!$data['user_code']) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_PARAMETER'
            ], 200);
        }

        $player = DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->where('apiType', 1)->first();

        if (!$player) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_USER'
            ], 200);
        }

        $agents = DB::table('agents')->where('agentCode', $data['agent_code'])->first();

        if ($agents->balance < $data['amount']) {
            return response()->json([
                'status' => 0,
                'msg' => 'INSUFFICIENT_AGENT_FUNDS'
            ], 200);
        }

        $agent_balance = $agents->balance - $data['amount'];

        DB::table('agents')->where('agentCode', $data['agent_code'])->update([
            'balance' => $agent_balance,
        ]);

        $player_balance = $player->balance + $data['amount'];

        DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->where('apiType', 1)->update([
            'balance' => $player_balance,
        ]);

        return response()->json([
            'status' => 1,
            'msg' => 'SUCCESS',
            'agent_balance' => $agents->balance,
            'user_balance' => $player_balance
        ], 200);
    }

    function balanceWithdraw($data)
    {
        $agentscs = DB::table('agents')->where('agentCode', $data['agent_code'])->first();

        if ($agentscs->apiType != 1) {
            return response()->json([
                'status' => 0,
                'msg' => 'AGENT_SEAMLESS'
            ], 200);
        }

        if (!$data['user_code']) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_PARAMETER'
            ], 200);
        }

        $player = DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->where('apiType', 1)->first();

        if (!$player) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_USER'
            ], 200);
        }

        if ($player->balance < $data['amount']) {
            return response()->json([
                'status' => 0,
                'msg' => 'INSUFFICIENT_USER_FUNDS'
            ], 200);
        }

        $agents = DB::table('agents')->where('agentCode', $data['agent_code'])->first();
        $agent_balance = $agents->balance + $data['amount'];

        DB::table('agents')->where('agentCode', $data['agent_code'])->update([
            'balance' => $agent_balance,
        ]);

        $player_balance = $player->balance - $data['amount'];

        DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->where('apiType', 1)->update([
            'balance' => $player_balance,
        ]);

        return response()->json([
            'status' => 1,
            'msg' => 'SUCCESS',
            'agent_balance' => $agents->balance,
            'user_balance' => $player_balance
        ], 200);
    }

    function balanceWithdrawAll($data)
    {
        $agentscs = DB::table('agents')->where('agentCode', $data['agent_code'])->first();

        if ($agentscs->apiType != 1) {
            return response()->json([
                'status' => 0,
                'msg' => 'AGENT_SEAMLESS'
            ], 200);
        }

        if (isset($data['user_code'])) {
            $player = DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->where('apiType', 1)->first();

            if (!$player) {
                return response()->json([
                    'status' => 0,
                    'msg' => 'INVALID_USER'
                ], 200);
            }

            $ball_bef = $player->balance;

            $agents = DB::table('agents')->where('agentCode', $data['agent_code'])->first();
            $agent_balance = $agents->balance + $player->balance;

            DB::table('agents')->where('agentCode', $data['agent_code'])->update([
                'balance' => $agent_balance,
            ]);

            $player_balance = $player->balance - $player->balance;

            DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->where('apiType', 1)->update([
                'balance' => $player_balance,
            ]);

            return response()->json([
                'status' => 1,
                'msg' => 'SUCCESS',
                'agent' => [
                    'agent_code' => $data['agent_code'],
                    'balance' => $agents->balance
                ],
                'user' => [
                    'user_code' => $player->userCode,
                    'withdraw_amount' => $ball_bef,
                    'balance' => $player_balance
                ]
            ], 200);
        } else {
            $players = DB::table('users')->where('balance', '>', 0)->where('agentCode', $data['agent_code'])->where('apiType', 1)->get();

            foreach ($players as $player) {
                $ball_bef = $player->balance;
                $player_balance = $player->balance - $player->balance;

                $agents = DB::table('agents')->where('agentCode', $data['agent_code'])->first();
                $agent_balance = $agents->balance + $player->balance;

                DB::table('agents')->where('agentCode', $data['agent_code'])->update([
                    'balance' => $agent_balance,
                ]);

                DB::table('users')->where('balance', '>', 0)->where('agentCode', $data['agent_code'])->where('apiType', 1)->update([
                    'balance' => $player_balance,
                ]);
            }

            return response()->json([
                'status' => 1,
                'msg' => 'SUCCESS',
                'agent' => [
                    'agent_code' => $data['agent_code'],
                    'balance' => $agents->balance
                ],
                'user_list' => [
                    'user_code' => $player->userCode,
                    'withdraw_amount' => $ball_bef,
                    'balance' => $player_balance
                ]
            ], 200);
        }
    }

    function launch_game($data)
    {
        if (!$data['user_code']) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_PARAMETER'
            ], 200);
        }

        $agentapi = DB::table('agents')->where('agentCode', $data['agent_code'])->first();

        if ($agentapi->balance == 0) {
            return response()->json([
                'status' => 0,
                'msg' => 'INSUFFICIENT_AGENT_FUNDS'
            ], 200);
        }

        $apis = ApiProvider::first();
        $player = DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->where('apiType', 1)->first();

        if ($agentapi->apiType == 0) {

            $postArray = [
                'method' => 'user_balance',
                'agent_code' => $agentapi->agentCode,
                'agent_secret' => $agentapi->secretKey,
                'user_code' => $data['user_code']
            ];
            $jsonData = json_encode($postArray);


            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $agentapi->siteEndPoint . '/gold_api',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $jsonData,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json'
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);

            $result = json_decode($response);

            if (!isset($result->status)) {
                return response()->json([
                    'status' => 0,
                    'msg' => 'AGENT_SEAMLESS'
                ], 200);
            }

            if ($result->status == 0) {
                return response()->json([
                    'status' => 0,
                    'msg' => 'INSUFFICIENT_USER_FUNDS'
                ], 200);
            }

            $player_check = DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->where('apiType', 0)->first();

            if (!$player_check) {
                DB::table('users')->insert([
                    'agentCode' => $data['agent_code'],
                    'userCode' => $data['user_code'],
                    'targetRtp' => 80,
                    'realRtp' => 80,
                    'balance' => 0,
                    'aasUserCode' => $data['user_code'],
                    'status' => 1,
                    'parentPath' => 1,
                    'totalDebit' => 0,
                    'totalCredit' => 0,
                    'apiType' => $agentapi->apiType,
                    'created_at' => date("Y-m-d H:i:s"),
                    'updated_at' => date("Y-m-d H:i:s")
                ]);
            }
        } else {
            if (!$player) {
                return response()->json([
                    'status' => 0,
                    'msg' => 'INVALID_USER'
                ], 200);
            }
        }


        $api = new CallbackController();
        $launch = $api->launchGame($data['game_type'],0,$data['ProviderCode'],$data['user_code']);

        return response()->json([
            'status' => 1,
            'msg' => 'SUCCESS',
            'launch_url' => $launch->Url
        ], 200);


    }

    function provider_list($data)
    {
        $data = DB::table('providers')->get(['code', 'name', 'type', 'status']);
        return response()->json([
            'status' => 1,
            'msg' => 'SUCCESS',
            'providers' => $data
        ], 200);
    }

    function game_list($data)
    {
        if (!isset($data['provider_code'])) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_PARAMETER'
            ], 200);
        }

        $games = DB::table('game_lists')->where('provider_code', $data['provider_code'])->select('id', 'game_code', 'game_name', 'banner', 'status')->get();
        return response()->json([
            'status' => 1,
            'msg' => 'SUCCESS',
            'games' => $games
        ], 200);
    }


    function curl_get($endpoint)
    {

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        return json_decode($response);
    }

    public function games_lobby(Request $request)
    {
        $games = DB::table('games_play')->where('hash', $request->session)->first();

        if (!$games) {
            abort(404);
        }
        return view('iframe', compact('games'));
    }

    function generateRandomString($length = 10)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }

        return $randomString;
    }

    public function provider_save(Request $request)
    {

        $count = DB::table('game_lists')->where('provider_code', $request->provider)->count();
        DB::table('providers')->insert([
            'code' => $request->provider,
            'name' => $request->provider,
            'type' => $request->provider,
            'endpoint' => Str::random(6),
            'status' => 1,
            'config' => $request->provider,
            'totalGames' => $count,
            'runningGames' => $count,
            'checkingGames' => $count,
            'created_at' => date("Y-m-d H:i:s"),
            'updated_at' => date("Y-m-d H:i:s"),
        ]);

        return "success";
    }
}
