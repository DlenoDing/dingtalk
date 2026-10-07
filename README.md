# dingtalk
钉钉告警

## 去重与限频

- `configs.<分组>.frequency`：同一消息在多少秒内只发送一次（`notice` 按正文，`exception` 按下面的去重方式）。
- `frequency`（顶层）：单个机器人每分钟最多发送条数（钉钉限制 20 条/分钟，超过限流 10 分钟），默认 20。
- `exception_dedup`（顶层，或分组内同名键覆盖）：异常消息的去重方式。
  - `message`（默认）：按异常消息原文，与旧版本一致。
  - `fingerprint`：按异常类、code、出错文件与行号、去除变量后的消息（数字、引号内容、16 位以上十六进制串替换为占位符）。同一处抛出、仅变量不同的异常（如带绑定值的 SQL、带耗时的网络错误）在去重窗口内只发送一次；不同位置或不同消息结构仍分别发送。

```php
return [
    'exception_dedup' => 'fingerprint',
    'configs' => [
        'trace' => [
            // 'exception_dedup' => 'message', // 分组单独覆盖
        ],
    ],
];
```

## 发布

推送 `v*` tag 后，GitHub Actions 会自动创建 GitHub Release，并自动生成发布说明；同名 Release 已存在时跳过。Composer 包版本仍由 Git tag 决定，Packagist 自动收录。

```bash
git tag v3.1.5
git push origin v3.1.5
```

仅推送 `main` 不会自动生成版本号或触发 Release。本工作流不额外上传构建产物，源码 ZIP 由 GitHub 提供。
