<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Ratchet\{ MessageComponentInterface, ConnectionInterface };
use App\Models\{ User, Chat, Chat_request };

use Auth;

class SocketController extends Controller implements MessageComponentInterface
{
    protected $clients;

    public function __construct()
    { 
        //* untuk menampung data objek-JSON (sama seperti array[]), bedanya ini tidak menerima objek duplikat
        $this->clients = new \SplObjectStorage; 
    }

    //* akan dipanggil ketika permintaan koneksi baru telah diterima / koneksi established
    public function onOpen(ConnectionInterface $conn)
    {
        //TODO      ::        ConnectionInterface $conn       ::
        //*  Objek proxy yang mewakili koneksi ke aplikasi, ini bertindak sebagai wadah untuk menyimpan data (dalam memori) tentang koneksi tersebut

        //! tambah koneksi data baru
        $this->clients->attach($conn); //* menambahkan objek $conn (store connection_id) ke $this->client

        //! isi variable dengan request URI dari objek koneksi established, dan convert to string
        $querystring = $conn->httpRequest->getUri()->getQuery(); //* return URI koneksi established berupa string
        parse_str($querystring, $queryarray); //! convert string to array

        if(isset($queryarray['token']))
        {
            User::where('token', $queryarray['token'])->update([
                //! fill column connection_id dengan $->resourceId
                'connection_id' => $conn->resourceId,
                'user_status' => 'Online'
            ]);

            $user_id = User::select('id')->where('token', $queryarray['token'])->get();
            $data['id'] = $user_id[0]->id;
            $data['status'] = 'Online';

            //* looping untuk kirim data ke user terkoneksi
            foreach($this->clients as $client)
            {
                //* 
                if($client->resourceId != $conn->resourceId)
                {
                    //! kirim status online ke user lain
                    //? tidak dengan user login
                    $client->send(json_encode($data));
                }
            }
        }

    }

    //* akan dipanggil ketika message dikirim, jadi nerima data dari client
    public function onMessage(ConnectionInterface $conn, $msg)
    {
        if(preg_match('~[^\x20-\x7E\t\r\n]~', $msg) > 0)
        {
            //receiver image in binary string message
            $image_name = time() . '.jpg';
            file_put_contents(public_path('images/') . $image_name, $msg);
            $send_data['image_link'] = $image_name;

            foreach($this->clients as $client)
            {
                if($client->resourceId == $conn->resourceId)
                {
                    $client->send(json_encode($send_data));
                }
            }
        }

        $data = json_decode($msg); //* nerima data dari client dan mengconvert to array
        if(isset($data->type))
        {
            if($data->type == 'request_load_unconnected_user')
            {
                //* get list user yg mau dichat
                $user_data = User::select('id', 'name', 'user_status', 'user_image')
                                    ->where('id', '!=', $data->from_user_id)
                                    ->orderBy('name', 'ASC')
                                    ->get();

                $sub_data = [];

                foreach($user_data as $row)
                {
                    $sub_data[] = [
                        'name'      => $row['name'],
                        'id'        => $row['id'],
                        'status'    => $row['user_status'],
                        'user_image'=> $row['user_image']
                    ];
                }

                //* data id pengirim
                $sender_connection_id = User::select('connection_id')->where('id', $data->from_user_id)->get();
                $send_data['data'] = $sub_data; //! isi dengan data yg diset ketika loop diatas
                $send_data['response_load_unconnected_user'] = true;

                foreach($this->clients as $client)
                {
                    //* cek apakah koneksi websocket (=berarti user auth) == koneksi user pengirim
                    if($client->resourceId == $sender_connection_id[0]->connection_id)
                    {
                        $client->send(json_encode($send_data));
                    }
                }
            }

            if($data->type == 'request_search_user')
            {
                //* get list user yg mau dichat sesuai yg diinputkan
                $user_data = User::select('id', 'name', 'user_status', 'user_image')
                                    ->where('id', '!=', $data->from_user_id)
                                    ->where('name', 'like', '%'.$data->search_query.'%')
                                    ->orderBy('name', 'ASC')
                                    ->get();

                $sub_data = [];

                foreach($user_data as $row)
                {
                    //* ambil data pengirim chat_request OR penerima chat_request
                    $chat_request = Chat_request::select('id')
                                                    ->where(function($query) use ($data, $row) {
                                                        $query
                                                            ->where('from_user_id', $data->from_user_id)
                                                            ->where('to_user_id', $row->id);
                                                    })
                                                    ->orWhere(function($query) use ($data, $row) {
                                                        $query
                                                            ->where('from_user_id', $row->id)
                                                            ->where('to_user_id', $data->from_user_id);
                                                    })
                                                    ->get();

                    /*
                    SELECT id FROM chat_request 
                    WHERE (from_user_id = $data->from_user_id AND to_user_id = $row->id) 
                    OR (from_user_id = $row->id AND to_user_id = $data->from_user_id)
                    */

                    //?    jadi setelah button send_request_chat diklik, maka user yg dipilih akan hilang dari list

                    //* ngecek kalo auth()->id dan id penerima chat_request kosong
                    if($chat_request->count() == 0)
                    {
                        //! maka tampilkan list data user yg tidak ada di tabel chat_request
                        //* isikan properti dgn hasil loop dari data yg diinputkan ketika searching
                        $sub_data[] = [
                            'name'      => $row['name'],
                            'id'        => $row['id'],
                            'status'    => $row['user_status'],
                            'user_image'=> $row['user_image']
                        ];
                    }
                }

                //* data id pengirim
                $sender_connection_id = User::select('connection_id')->where('id', $data->from_user_id)->get();
                $send_data['data'] = $sub_data; //! isi dengan sub_data yg diset di atas
                $send_data['response_search_user'] = true;

                foreach($this->clients as $client)
                {
                    //* cek apakah koneksi sesuai dengan koneksi user pengirim
                    if($client->resourceId == $sender_connection_id[0]->connection_id)
                    {
                        $client->send(json_encode($send_data)); //! kirim data ke dashboard dan convert to object
                    }
                }
            }

            //* ngecek data chat_request udah diterima/belum
            if($data->type == 'request_chat_user')
            {
                $chat_request = new Chat_request;
                $chat_request->from_user_id = $data->from_user_id;
                $chat_request->to_user_id = $data->to_user_id;
                $chat_request->status = 'Pending';
                $chat_request->save();

                //* ambil data connection_id sender_chat untuk dikirim respon
                $sender_connection_id = User::select('connection_id')->where('id', $data->from_user_id)->get();
                //* ambil data connection_id penerima untuk dikirim respon
                $receiver_connection_id = User::select('connection_id')->where('id', $data->to_user_id)->get();

                foreach($this->clients as $client)
                {
                    //* cek apakah user auth() == pengirim chat
                    if($client->resourceId == $sender_connection_id[0]->connection_id)
                    {
                        //* set key tsb jadi true untuk send response
                        $send_data['response_from_user_chat_request'] = true;

                        $client->send(json_encode($send_data)); //! kirim data ke dashboard dan convert to object
                    }

                    //* cek apakah koneksi websocket (=berarti user auth) sesuai dengan koneksi user penerima
                    if($client->resourceId == $receiver_connection_id[0]->connection_id)
                    {
                        $send_data['user_id'] = $data->to_user_id; //! set user_id jadi id user tujuan
                        $send_data['response_to_user_chat_request'] = true;

                        $client->send(json_encode($send_data)); //! kirim data ke dashboard dan convert to object
                    }
                }
            }

            if($data->type == 'request_load_unread_notification')
            {
                //* ngambil data di chat_request yg statusnya belum approve
                $notification_data = Chat_request::select('id', 'from_user_id', 'to_user_id', 'status')
                                                    ->where('status', '!=', 'Approve')
                                                    ->where(function($query) use ($data) {
                                                        $query
                                                            ->where('from_user_id', $data->user_id)
                                                            ->orWhere('to_user_id', $data->user_id);
                                                    })->orderBy('id', 'ASC')
                                                    ->get();

                /*
                SELECT id, from_user_id, to_user_id, status FROM chat_requests
                WHERE status != 'Approve'
                AND (from_user_id = $data->user_id OR to_user_id = $data->user_id)
                ORDER BY id ASC
                */
                $sub_data = [];

                foreach($notification_data as $row)
                {
                    $user_id = '';
                    $notification_type = '';
                    //* cek apakah from_user_id (dari data yg di loop) == user_id yang dari $msg (=berarti where user auth)
                    //? maksudnya kalo true, di user auth() akan menampilkan yg didalam block
                    if($row->from_user_id == $data->user_id)
                    { //* akan ada notif di user yg ngirim request
                        //! set notifnya
                        $user_id = $row->to_user_id;
                        $notification_type = 'Send Request';
                    }
                    else
                    { //! set notif
                        //* jadi di user yg dikirimin request_chat, akan muncul notif
                        $user_id = $row->from_user_id;
                        $notification_type = 'Receive Request';
                    }

                    //* data untuk menampilkan user di list notification
                    $user_data = User::select('name', 'user_image')->where('id', $user_id)->first();

                    $sub_data[] = [
                        'id'               => $row->id,
                        'from_user_id'     => $row->from_user_id,
                        'to_user_id'       => $row->to_user_id,
                        'name'             => $user_data->name,
                        'notification_type'=> $notification_type,
                        'status'           => $row->status,
                        'user_image'       => $user_data->user_image
                    ];
                }

                //* ambil connection_id user pengirim
                $sender_connection_id = User::select('connection_id')->where('id', $data->user_id)->get();

                foreach($this->clients as $client)
                {
                    //* cek apakah koneksi sesuai dengan koneksi auth()->user
                    if($client->resourceId == $sender_connection_id[0]->connection_id)
                    {
                        $send_data['response_load_notification'] = true;
                        $send_data['data'] = $sub_data;
                        
                        $client->send(json_encode($send_data)); //! kirim data ke dashboard dan convert to object
                    }
                }
            }

            if($data->type == 'request_process_chat_request')
            {
                //* ambil data chat_request untuk diubah statusnya (to be reject/approve)
                Chat_request::where('id', $data->chat_request_id)->update([
                    'status' => $data->action
                ]);

                //* ambil connection_id user pengirim chat_request
                $sender_connection_id = User::select('connection_id')->where('id', $data->from_user_id)->get();
                //* ambil connection_id user penerima chat_request
                $receiver_connection_id = User::select('connection_id')->where('id', $data->to_user_id)->get();

                foreach($this->clients as $client)
                {
                    $send_data['response_process_chat_request'] = true;

                    //TODO:::::::  SEND DATA KE USER PENGIRIM   :::::::

                    //* cek apakah user auth() == pengirim chat
                    if($client->resourceId == $sender_connection_id[0]->connection_id)
                    {
                        $send_data['user_id'] = $data->from_user_id; //! set user_id jadi id user pengirim
                    }
                    
                    //* cek apakah koneksi websocket (=berarti user auth) sesuai dengan koneksi user penerima
                    if($client->resourceId == $receiver_connection_id[0]->connection_id)
                    {
                        $send_data['user_id'] = $data->to_user_id; //! set user_id jadi id user penerima
                    }

                    $client->send(json_encode($send_data)); //! kirim data ke dashboard dan convert to object
                }
            }

            if($data->type == 'request_connected_chat_user')
            {
                $condition_1 = [
                    //! isikan dengan id auth()
                    'from_user_id' => $data->from_user_id,
                    'to_user_id' => $data->from_user_id
                ];

                //* get data user yg Approve
                //? entah auth() yg ngerequest / auth() yg nerima request
                $user_id_data = Chat_request::select('from_user_id', 'to_user_id')
                                                ->orWhere($condition_1)
                                                ->where('status', 'Approve')
                                                ->get();

                /*
                SELECT from_user id, to_user_id FROM chat_requests 
                WHERE (from_user_id = $data->from_user_id OR to_user_id = $data->from_user_id) 
                AND status = 'Approve'
                */
                $sub_data = [];

                //TODO:::::::  NGE GET DATA USER YG UDAH APPROVE REQUEST CHAT   :::::::

                foreach($user_id_data as $user_id_row)
                {
                    $user_id = '';

                    //* kalo dia user yg nerima request
                    if($user_id_row->from_user_id != $data->from_user_id)
                    {
                        $user_id = $user_id_row->from_user_id; //! isikan dengan from_user_id yg diloop
                    }
                    else
                    //* kalo dia user pengirim request
                    {
                        $user_id = $user_id_row->to_user_id; //! isikan dengan to_user_id yg diloop
                    }

                    //* ambil data user berdasarkan id dari kondisi if diatas
                    $user_data = User::select('id', 'name', 'user_image', 'user_status', 'updated_at')
                                        ->where('id', $user_id)
                                        ->first();

                    if(date('Y-m-d') == date('Y-m-d', strtotime($user_data->updated_at)))
                    {
                        $last_seen = 'Last Seen At ' . date('H:i', strtotime($user_data->updated_at));
                    }
                    else
                    {
                        $last_seen = 'Last Seen At ' . date('d/m/Y H:i', strtotime($user_data->updated_at));
                    }

                    $sub_data[] = [
                        'id'         => $user_data->id,
                        'name'       => $user_data->name,
                        'user_image' => $user_data->user_image,
                        'user_status'=> $user_data->user_status,
                        'last_seen'  => $last_seen
                    ];
                }

                //* ambil data pengirim request 
                $sender_connection_id = User::select('connection_id')->where('id', $data->from_user_id)->get();

                //TODO:::::::  SEND DATA TO USER LOGIN   :::::::

                foreach($this->clients as $client)
                {
                    //* cek apakah user auth() == pengirim chat
                    if($client->resourceId == $sender_connection_id[0]->connection_id)
                    {
                        $send_data['response_connected_chat_user'] = true;
                        $send_data['data'] = $sub_data;
                        $client->send(json_encode($send_data));
                    }
                }
            }

            if($data->type == 'request_send_message')
            {
                //? data yg diterima dari dashboard, diinputkan ke class Chat

                //save chat message in mysql
                $chat = new Chat;
                $chat->from_user_id = $data->from_user_id;
                $chat->to_user_id = $data->to_user_id;
                $chat->chat_message = $data->message;
                $chat->message_status = 'Not Send';
                $chat->save();

                //! ambil id chat yg baru dimasukkan ke tabel (yg baru disave)
                $chat_message_id = $chat->id;

                //* ambil connection_id user penerima chat
                $receiver_connection_id = User::select('connection_id')->where('id', $data->to_user_id)->get();
                //* ambil connection_id user pengirim chat
                $sender_connection_id = User::select('connection_id')->where('id', $data->from_user_id)->get();

                foreach($this->clients as $client)
                {
                    //* cek apakah user penerima yg login
                    //?     OR
                    //* cek apakah user pengirim yg login
                    if($client->resourceId == $receiver_connection_id[0]->connection_id || $client->resourceId == $sender_connection_id[0]->connection_id)
                    {
                        $send_data['chat_message_id'] = $chat_message_id;
                        $send_data['message'] = $data->message;
                        $send_data['from_user_id'] = $data->from_user_id;
                        $send_data['to_user_id'] = $data->to_user_id;

                        //* kalo user penerima login
                        if($client->resourceId == $receiver_connection_id[0]->connection_id)
                        {
                            //* ambil data chat yg baru disave diatas
                            //! ubah statusnya jadi terkirim
                            Chat::where('id', $chat_message_id)->update([
                                'message_status' =>'Send'
                            ]);
                            $send_data['message_status'] = 'Send';
                        }
                        //* kalo user pengirim login
                        else
                        {
                            //! statusnya not send, karena penerima belum login
                            $send_data['message_status'] = 'Not Send';
                        }

                        $client->send(json_encode($send_data)); //! kirim data ke dashboard dan convert to object
                    }
                }
            }

            if($data->type == 'request_chat_history')
            {
                //* get data tabel chat berdasarkan user auth dan user yg diklik
                $chat_data = Chat::select('id', 'from_user_id', 'to_user_id', 'chat_message', 'message_status')
                                    ->where(function($query) use ($data) {
                                        $query
                                            ->where('from_user_id', $data->from_user_id)
                                            ->where('to_user_id', $data->to_user_id);
                                    })
                                    ->orWhere(function($query) use ($data) {
                                        $query
                                            ->where('from_user_id', $data->to_user_id)
                                            ->where('to_user_id', $data->from_user_id);
                                    })
                                    ->orderBy('id', 'ASC')
                                    ->get();
                /*
                SELECT id, from_user_id, to_user_id, chat_message, message status 
                FROM chats 
                WHERE (from_user_id = $data->from_user_id AND to_user_id = $data->to_user_id) 
                OR (from_user_id = $data->to_user_id AND to_user_id = $data->from_user_id)
                ORDER BY id ASC
                */
                $send_data['chat_history'] = $chat_data;

                //* ambil connection_id user penerima chat
                $receiver_connection_id = User::select('connection_id')->where('id', $data->from_user_id)->get();

                foreach($this->clients as $client)
                {
                    //* kalo dia penerima chat
                    if($client->resourceId == $receiver_connection_id[0]->connection_id)
                    {
                        //! kirim datanya, jadi bakal nampil di chat history
                        $client->send(json_encode($send_data));
                    }
                }
            }

            if($data->type == 'update_chat_status')
            {
                //update chat status
                Chat::where('id', $data->chat_message_id)->update([
                    'message_status' => $data->chat_message_status
                ]);

                //* ambil connection_id user penerima chat
                $sender_connection_id = User::select('connection_id')->where('id', $data->from_user_id)->get();

                foreach($this->clients as $client)
                {
                    //* kalo user yg login == pengirim chat
                    if($client->resourceId == $sender_connection_id[0]->connection_id)
                    {
                        $send_data['update_message_status'] = $data->chat_message_status;
                        $send_data['chat_message_id'] = $data->chat_message_id;
                        $client->send(json_encode($send_data));
                    }
                }
            }

            if($data->type == 'check_unread_message')
            {
                $chat_data = Chat::select('id', 'from_user_id', 'to_user_id')
                                    ->where('message_status', '!=', 'Read')
                                    ->where('from_user_id', $data->to_user_id)
                                    ->get();

                /*
                SELECT id, from_user_id, to_user_id FROM chats 
                WHERE message_status != 'Read'
                AND from_user_id = $data->to_user_id
                */

                //* ambil connection_id user pengirim chat
                //? untuk ambil jumlah pesan yg belum dibaca
                $sender_connection_id = User::select('connection_id')->where('id', $data->from_user_id)->get();

                //* ambil connection_id user penerima chat
                //? untuk set notif pesan di read
                $receiver_connection_id = User::select('connection_id')->where('id', $data->to_user_id)->get();

                foreach($chat_data as $row)
                {
                    //* ubah status chatnya == 'Send' (karna penerima chat udah login, tapi belum nge-read)
                    Chat::where('id', $row->id)->update([
                        'message_status' => 'Send'
                    ]);

                    foreach($this->clients as $client)
                    {
                        //* kalo user yg login == pengirim chat
                        if($client->resourceId == $sender_connection_id[0]->connection_id)
                        {
                            $send_data['count_unread_message'] = 1; //! set jumlah pesan yg unread + 1
                            $send_data['chat_message_id'] = $row->id;
                            $send_data['from_user_id'] = $row->from_user_id;
                        }

                        //* kalo user yg login == penerima chat
                        if($client->resourceId == $receiver_connection_id[0]->connection_id)
                        {
                            $send_data['update_message_status'] = 'Send';
                            $send_data['chat_message_id'] = $row->id;
                            $send_data['unread_msg'] = 1;
                            $send_data['from_user_id'] = $row->from_user_id;
                        }

                        $client->send(json_encode($send_data));
                    }
                }
            }
        }
    }

    //* dipanggil ketika websocket connection telah dimatikan
    public function onClose(ConnectionInterface $conn)
    {
        //! matikan koneksi websocket
        $this->clients->detach($conn);

        //! isi variable dengan request URI dari objek koneksi established, dan convert to string
        $querystring = $conn->httpRequest->getUri()->getQuery(); //* return URI koneksi established berupa string
        parse_str($querystring, $queryarray); //! convert string to array

        if(isset($queryarray['token']))
        {
            User::where('token', $queryarray['token'])->update([
                //! ubah column connection_id jadi 0
                'connection_id' => 0,
                'user_status' => 'Offline'
            ]);

            //* ambil data user auth ketika proses logout
            //? untuk diubah updated_at nya dan statusnya jadi offline
            $user_id = User::select('id', 'updated_at')->where('token', $queryarray['token'])->get();
            $data['id'] = $user_id[0]->id;
            $data['status'] = 'Offline';
            $updated_at = $user_id[0]->updated_at;

            if(date('Y-m-d') == date('Y-m-d', strtotime($updated_at))) //Same Date, so display only Time
            {
                $data['last_seen'] = 'Last Seen at ' . date('H:i');
            }
            else
            {
                $data['last_seen'] = 'Last Seen at ' . date('d/m/Y H:i');
            }

            //* looping untuk kirim data ke user terkoneksi
            foreach($this->clients as $client)
            {
                if($client->resourceId != $conn->resourceId)
                {
                    //! kirim status offline ke user lain
                    //? tidak dengan user login
                    $client->send(json_encode($data));
                }
            }
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "An error has occurred: {$e->getMessage()} \n";
        $conn->close();
    }
}
