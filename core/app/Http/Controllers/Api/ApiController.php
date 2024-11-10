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

use Yajra\DataTables\Facades\DataTables;

class ApiController extends Controller
{

    public function methode(Request $request)
    {
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
            ], 422);
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
            default:
                abort(404);
        }
    }

    function PlayerAccountCreate($data)
    {
        if (!$data['user_code']) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_PARAMETER'
            ], 200);
        }

        $player = DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->first();

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

        $this->api_create($data['user_code']);

        return response()->json([
            'status' => 1,
            'msg' => 'SUCCESS',
            'user_code' => $data['user_code'],
            'user_balance' => 0
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

        $balance = $this->api_balance($data['user_code']);
        $data = $balance->data;

        DB::table('users')->where('userCode', $data['MemberName'])->update([
            'balance' => $data->balance,
        ]);

        $player = DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->first();

        if (!$player) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_USER'
            ], 200);
        }

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

    function balanceTopup($data)
    {
        if (!$data['user_code']) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_PARAMETER'
            ], 200);
        }

        $player = DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->first();

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

        DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->update([
            'balance' => $player_balance,
        ]);

        $this->api_transaksi($data['user_code'],$data['amount'],'deposit');

        return response()->json([
            'status' => 1,
            'msg' => 'SUCCESS',
            'agent_balance' => $agents->balance,
            'user_balance' => $player_balance
        ], 200);
    }

    function balanceWithdraw($data)
    {
        if (!$data['user_code']) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_PARAMETER'
            ], 200);
        }

        $player = DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->first();

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

        DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->update([
            'balance' => $player_balance,
        ]);

        $this->api_transaksi($data['user_code'],$data['amount'],'withdraw');

        return response()->json([
            'status' => 1,
            'msg' => 'SUCCESS',
            'agent_balance' => $agents->balance,
            'user_balance' => $player_balance
        ], 200);
    }

    function balanceWithdrawAll($data)
    {
        if (isset($data['user_code'])) {
            $player = DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->first();

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

            DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->update([
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
            $players = DB::table('users')->where('balance', '>', 0)->where('agentCode', $data['agent_code'])->get();

            foreach ($players as $player) {
                $ball_bef = $player->balance;
                $player_balance = $player->balance - $player->balance;

                $agents = DB::table('agents')->where('agentCode', $data['agent_code'])->first();
                $agent_balance = $agents->balance + $player->balance;

                DB::table('agents')->where('agentCode', $data['agent_code'])->update([
                    'balance' => $agent_balance,
                ]);

                DB::table('users')->where('balance', '>', 0)->where('agentCode', $data['agent_code'])->update([
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

        $apis = ApiProvider::first();
        $player = DB::table('users')->where('userCode', $data['user_code'])->where('agentCode', $data['agent_code'])->first();
        $agentapi = DB::table('agents')->where('agentCode', $data['agent_code'])->first();

        if (!$player) {
            return response()->json([
                'status' => 0,
                'msg' => 'INVALID_USER'
            ], 200);
        }


        $result = $this->api_launch($data['user_code'],$data['game_code'],$data['provider_code']);

        if ($result->status == 'success') {
            return response()->json([
                'status' => 1,
                'msg' => 'SUCCESS',
                'launch_url' => $result->gameUrl
            ], 200);
        } else {
            return response()->json([
                'status' => 0,
                'msg' => 'INTERNAL_ERROR'
            ], 200);
        }
    }

    function game_list($data)
    {
        return $this->api_game();
    }

    function generateSign($OperatorCode, $RequestTime, $MethodName, $SecretKey)
    {
        $sign = md5($OperatorCode . $RequestTime . $MethodName . $SecretKey);
        return $sign;
    }

    function provider_list($data)
    {

        return $this->api_provider();
    }

    function api_create($username)
    {
        $url = "https://api.88xgames.com/v2/CreateMember.aspx?agent_token=c3b52b25c5f6d7f036fb636816813506&agent_code=xwgv59Xj&username={$username}";
        return $this->curl_get($url);
    }

    function api_balance($username)
    {
        $url = "https://api.88xgames.com/v2/GetBalance.ashx?agent_token=c3b52b25c5f6d7f036fb636816813506&agent_code=xwgv59Xj&username={$username}";
        return $this->curl_get($url);
    }

    function api_provider()
    {
        $url = "https://api.88xgames.com/v2/GetProviderList.aspx?agent_token=c3b52b25c5f6d7f036fb636816813506&agent_code=xwgv59Xj";
        return $this->curl_get($url);
    }

    function api_game()
    {
        $url = "https://api.88xgames.com/v2/GetGameList.aspx?agent_token=c3b52b25c5f6d7f036fb636816813506&agent_code=xwgv59Xj";
        return $this->curl_get($url);
    }

    function api_transaksi($username,$amount,$type)
    {
        $txid = $this->generateRandomString();
        $url = "https://api.88xgames.com/v2/MakeTransfer.aspx?agent_token=c3b52b25c5f6d7f036fb636816813506&agent_code=xwgv59Xj&username={$username}&amount={$amount}&type={$type}&txid={$txid}";
        return $this->curl_get($url);
    }

    function api_launch($username,$game_code,$game_provider)
    {
        $url = "https://api.88xgames.com/v2/LaunchGame.aspx?agent_token=c3b52b25c5f6d7f036fb636816813506&agent_code=xwgv59Xj&username={$username}&game_type=SeamlessGame&game_code={$game_code}&game_provider={$game_provider}&lang=en";
        return $this->curl_get($url);
    }


    function curl_get($endpoint)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_AUTOREFERER, TRUE);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.47 Safari/537.36');
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $output = curl_exec($ch);
        curl_close($ch);
        return json_decode($output, true);
    }

    function generateRandomString($length = 10) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }

        return $randomString;
    }

}
