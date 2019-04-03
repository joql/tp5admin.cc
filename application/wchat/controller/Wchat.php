<?php
/**
 * Wchat.php
 *
 */

namespace app\wchat\controller;
use EasyWeChat\Factory;
use EasyWeChat\Kernel\Messages\News;
use EasyWeChat\Kernel\Messages\NewsItem;
use EasyWeChat\Kernel\Messages\Text;
use think\Controller;

class Wchat extends Controller
{
    public $config;
    protected $app; //easywechat实例类

    public function initialize()
    {
        parent::initialize();
        $value = db('wx_config')->where([ 'key' => 'SHOPWCHAT'])->value('value');
        $this->config = json_decode($value,true);
        //实例化easywechat类
        $config = [
            'app_id' => $this->config['appid'],
            'secret' => $this->config['secret'],
            'token' => $this->config['token'],
            'response_type' => 'array',
            'log' => [
                'level' => 'debug',
                'file' => env('root_path').'runtime/log/wechat.log',
            ],
        ];
        $this->app = Factory::officialAccount($config);
        $this->getMessage();
    }

    /**
     * ************************************************************************微信公众号消息相关方法 开始******************************************************
     */
    /**
     * 关联公众号微信并返回消息内容
     */
    public function relateWeixin()
    {
        $response = $this->app->server->serve();
        $response->send();
    }

    /**
     * use for:处理微信服务器返回消息
     * auth: Joql
     * date:2019-04-02 11:53
     */
    public function getMessage()
    {

        $this->app->server->push(function ($message){
           switch ($message['MsgType']){
               case 'event':
                   return $this->MsgTypeEvent($message);
                   break;
               case 'text':
                   //用户发的消息   存入表中
                   return $this->MsgTypeText($message);
               // ... 其它消息
               default:
                   return 'success';
                   break;
           }
        });
    }

    /**
     * 文本消息回复格式
     *
     * @param unknown $postObj
     * @return Ambigous <void, string>
     */
    private function MsgTypeText($postObj)
    {
        $funcFlag = 0; // 星标
        $wchat_replay = $this->getWhatReplay(0, (string)$postObj['Content']);

        // 判断用户输入text
        if (!empty($wchat_replay)) { // 关键词匹配回复
            $contentStr = $wchat_replay; // 构造media数据并返回
        } else {
            $content = $this->getDefaultReplay();
            if (!empty($content)) {
                $contentStr = $content;
            } else {
                $contentStr = '欢迎！';
            }
        }

        return $contentStr;
    }

    /**
     * 事件消息回复机制
     */
    // 事件自动回复 MsgType = Event
    private function MsgTypeEvent($postObj)
    {
        $contentStr = "";
        switch ($postObj['Event']) {
            case "subscribe": // 关注公众号 添加关注回复
                $content = $this->getSubscribeReplay();
                if (!empty($content)) {
                    $contentStr = $content;
                }
                // 构造media数据并返回
                break;
            case "unsubscribe": // 取消关注公众号
                break;
            case "VIEW": // VIEW事件 - 点击菜单跳转链接时的事件推送
                // $this->wchat->weichat_menu_hits_view($postObj->EventKey); //菜单计数
                $contentStr = "";
                break;
            case "SCAN": // SCAN事件 - 用户已关注时的事件推送
                $contentStr = "";
                break;
            case "CLICK": // CLICK事件 - 自定义菜单事件
                $menu_detail = $this->getWeixinMenuDetail($postObj['EventKey']);
                $media_info = $this->getWeixinMediaDetail($menu_detail['media_id']);
                $contentStr = $this->getMediaWchatStruct($media_info); // 构造media数据并返回
                break;
            default:
                break;
        }
        return $contentStr;
    }

    /**********************************/

    /**
     * use for: 获取菜单详情
     * auth: Joql
     * @param $menu_id
     * @return array|null|\PDOStatement|string|\think\Model
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * date:2019-04-02 9:53
     */
    private function getWeixinMenuDetail($menu_id)
    {
        $weixin_menu = db('wx_menu');
        $data = $weixin_menu->where('menu_id',$menu_id)->find();
        return $data;
    }

    /**
     * use for:获取素材详情by菜单
     * auth: Joql
     * @param $media_id
     * @return array|null|\PDOStatement|string|\think\Model
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * date:2019-04-02 9:54
     */
    private function getWeixinMediaDetail($media_id)
    {
        $weixin_media = db('wx_media');
        $weixin_media_info = $weixin_media->where('media_id',$media_id)->find();
        if (! empty($weixin_media_info)) {
            $weixin_media_item = db('wx_media_item');
            $item_list = $weixin_media_item->where('media_id',$media_id)->select();
            $weixin_media_info['item_list'] = $item_list;
        }
        return $weixin_media_info;
    }

    /**
     * use for:构建素材数据返回by菜单
     * auth: Joql
     * @param $media_info
     * @return array|string
     * date:2019-04-02 9:56
     */
    private function getMediaWchatStruct($media_info){
        switch ($media_info['type']) {
            case "1":
                //文本消息
                $contentStr = new Text(trim($media_info['title']));
                break;
            case "2":
                //单图文
                $items = [
                    new NewsItem([
                        'title' => $media_info['item_list'][0]['title'],
                        'description' => $media_info['item_list'][0]['summary'],
                        'url' => url('templatemessage',['media_id'=>$media_info['item_list'][0]['id']],'',true),
                        'image' => 'http://' . $_SERVER['HTTP_HOST'] . '/public/' . $media_info['item_list'][0]['cover'],
                    ])
                ];
                $contentStr = new News($items);
                break;
            case "3":
                //多图文
                foreach ($media_info['item_list'] as $k => $v) {
                    $items[] = new NewsItem([
                        "title" => $v['title'],
                        "description" => $v['summary'],
                        "image" => 'http://' . $_SERVER['HTTP_HOST'] . '/public/' . $v['cover'],
                        "url" => url( 'templatemessage',['media_id'=>$v['id']],'',true)
                    ]);
                }
                $contentStr = new News($items);
                break;
            default:
                $contentStr = "";
                break;
        }
        return $contentStr;
    }

    /**
     * use for: 获取关注回复
     * auth: Joql
     * @param $instance_id
     * @return array|string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * date:2019-04-02 11:50
     */
    private function getSubscribeReplay($instance_id = 0)
    {
        $weixin_flow_replay = db('wx_follow_replay');
        $info = $weixin_flow_replay->where('instance_id',$instance_id)->find();
        if (! empty($info)) {
            $media_detail = $this->getWeixinMediaDetail($info['reply_media_id']);
            $content = $this->getMediaWchatStruct($media_detail);
            return $content;
        } else {
            return '';
        }
    }

    /**
     * use for:获取关键词回复
     * auth: Joql
     * @param int $instance_id
     * @param $key_words
     * @return array|string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * date:2019-04-03 8:53
     */
    private function getWhatReplay($instance_id = 0, $key_words)
    {
        $weixin_key_replay = db('wx_key_replay');
        // 全部匹配
        $condition = [
            ['instance_id','=',$instance_id],
            ['key','=',$key_words],
            ['match_type','=',2]
        ];
        $info = $weixin_key_replay->where($condition)->find();
        if (empty($info)) {
            // 模糊匹配
            $condition =
                [
                    ['instance_id','=',$instance_id],
                    ['key','LIKE','%' . $key_words . '%'],
                    ['match_type','=',1]
                ];
            $info = $weixin_key_replay->where($condition)->find();
        }
        if (! empty($info)) {
            $media_detail = $this->getWeixinMediaDetail($info['reply_media_id']);
            $content = $this->getMediaWchatStruct($media_detail);
            return $content;
        } else {
            return '';
        }
    }

    /**
     * use for:获取默认回复
     * auth: Joql
     * @param int $instance_id
     * @return array|string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * date:2019-04-03 8:53
     */
    private function getDefaultReplay($instance_id = 0){
        $weixin_default_replay = db('wx_default_replay');
        $info = $weixin_default_replay->where('instance_id',$instance_id)->find();
        if (!empty($info)) {
            $media_detail = $this->getWeixinMediaDetail($info['reply_media_id']);
            $content = $this->getMediaWchatStruct($media_detail);
            return $content;
        } else {
            return '';
        }
    }

    /**
     * use for:获取图文消息
     * auth: Joql
     * @return \think\response\View
     * date:2019-04-03 9:14
     */
    private function templatemessage()
    {
        $media_id = input('media_id',0);
        $info = $this->getWeixinMediaDetailByMediaId($media_id);
        if (! empty($info["media_parent"])) {
            $this->assign("info", $info);
            return view();
        } else {
            echo "图文消息没有查询到";
        }
    }

    /**
     * use for:获取图文消息详情
     * auth: Joql
     * @param $media_id
     * @return null
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @throws \think\exception\PDOException
     * date:2019-04-03 9:15
     */
    private function getWeixinMediaDetailByMediaId($media_id){
        $weixin_media_item =db('wx_media_item');
        $item_list = $weixin_media_item->where(['id' => $media_id])->find();
        if (!empty($item_list)) {
            // 主表
            $weixin_media = db('wx_media');
            $weixin_media_info["media_parent"] = $weixin_media->where(["media_id" => $item_list["media_id"] ])->find();

            // 微信配置
            $weixin_auth = db('wx_auth');
            $weixin_media_info["weixin_auth"] = $weixin_auth->where(["instance_id" => $weixin_media_info["media_parent"]["instance_id"]])->find();
            $weixin_media_info["media_item"] = $item_list;
            // 更新阅读次数
            $res = $weixin_media_item->where(["id" => $media_id])->update(["hits" => ($item_list["hits"] + 1)]);
            return $weixin_media_info;
        }
        return null;
    }

}