<?php

namespace TCoinSystem\TheWindows;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\Config;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\item\VanillaItems;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use onebone\economyapi\EconomyAPI;
use Ifera\ScoreHud\event\TagsResolveEvent;
use Ifera\ScoreHud\scoreboard\ScoreTag;
use pocketmine\event\Listener;
use jojoe77777\FormAPI\SimpleForm;
use jojoe77777\FormAPI\CustomForm;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat;

class Main extends PluginBase implements Listener {

	private $tcoinData;
	private $economyAPI;
	private $currentPrice;
	private $priceHistory = [];
	private $shopItems;
	private $orderData;
	private $usedCodes = [];
	private $lastPriceUpdateHour = -1;

	public function onEnable(): void {
		$this->saveDefaultConfig();
		$this->tcoinData = new Config($this->getDataFolder() . "tcoins.yml", Config::YAML);
		$this->orderData = new Config($this->getDataFolder() . "orders.yml", Config::YAML, []);

		foreach($this->orderData->getAll() as $order) {
			$this->usedCodes[$order['code']] = true;
		}

		$this->shopItems = new Config($this->getDataFolder() . "shop.yml", Config::YAML, [
			'items' => [
				[
					'name' => 'Tag Purchase',
					'price' => 50,
					'type' => 'tag',
					'image' => 'textures/items/name_tag'
				],
				[
					'name' => 'Mafia Purchase',
					'price' => 100,
					'type' => 'mafia',
					'image' => 'textures/items/paper'
				],
				[
					'name' => 'OP Sword',
					'price' => 200,
					'type' => 'op_sword',
					'image' => 'textures/items/diamond_sword'
				]
			]
		]);

		$this->economyAPI = EconomyAPI::getInstance();

		$this->initializePrice();

		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(): void {
			$this->checkPriceUpdate();
		}), 20 * 60);

		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	private function initializePrice(): void {
		$currentHour = (int)date('G');
		$this->lastPriceUpdateHour = $currentHour;

		// Load or initialize price
		$this->currentPrice = $this->getConfig()->get('current-price', 100000);
		$this->priceHistory = $this->getConfig()->get('price-history', []);

		if(empty($this->priceHistory)) {
			$this->priceHistory[] = [
				'time' => time(),
				'old_price' => $this->currentPrice,
				'new_price' => $this->currentPrice,
				'change' => 0,
				'hour' => $currentHour
			];
			$this->savePriceData();
		}
	}

	private function checkPriceUpdate(): void {
		$currentHour = (int)date('G');

		if($currentHour !== $this->lastPriceUpdateHour) {
			$this->lastPriceUpdateHour = $currentHour;
			$this->updatePrice();
		}
	}

	private function generateUniqueCode(): string {
		$characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$code = '';

		do {
			$code = '';
			for ($i = 0; $i < 8; $i++) {
				$code .= $characters[mt_rand(0, strlen($characters) - 1)];
			}
		} while (isset($this->usedCodes[$code]));

		$this->usedCodes[$code] = true;
		return $code;
	}

	private function recordOrder(Player $player, string $itemType, string $code): void {
		$orders = $this->orderData->getAll();
		$orderId = count($orders) + 1;

		$orders[$orderId] = [
			'player' => $player->getName(),
			'item' => $itemType,
			'code' => $code,
			'timestamp' => time(),
			'date' => date('Y-m-d H:i:s')
		];

		$this->orderData->setAll($orders);
		$this->orderData->save();

		$adminData = $this->tcoinData->get('admin_orders', []);
		$adminData[] = [
			'player' => $player->getName(),
			'item' => $itemType,
			'code' => $code,
			'timestamp' => time(),
			'date' => date('Y-m-d H:i:s')
		];
		$this->tcoinData->set('admin_orders', $adminData);
		$this->tcoinData->save();
	}

	private function updatePrice(): void {
		$currentHour = (int)date('G');
		$changePercent = mt_rand(-10, 10) / 100;
		$newPrice = round($this->currentPrice * (1 + $changePercent));

		if($newPrice < 90000) {
			$newPrice = 90000;
			$changePercent = abs(mt_rand(1, 10) / 100);
		}

		$this->priceHistory[] = [
			'time' => time(),
			'old_price' => $this->currentPrice,
			'new_price' => $newPrice,
			'change' => $changePercent,
			'hour' => $currentHour
		];

		$this->currentPrice = $newPrice;
		$this->savePriceData();

		foreach($this->getServer()->getOnlinePlayers() as $player) {
			$this->updateScoreHudTags($player);
			$direction = $changePercent >= 0 ? '§a↑' : '§c↓';
			$player->sendTip("§eTCoin Price: §6$" . number_format($newPrice) . " §7($direction" . abs(round($changePercent * 100)) . "%)");
		}
	}

	private function savePriceData(): void {
		$this->getConfig()->set('current-price', $this->currentPrice);
		$this->getConfig()->set('price-history', $this->priceHistory);
		$this->getConfig()->save();
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
		switch(strtolower($command->getName())) {
			case "tcoin":
				if(!$sender instanceof Player) {
					$sender->sendMessage("§cThis command can only be used in-game!");
					return false;
				}
				$this->openMainForm($sender);
				return true;

			case "tcoinadmin":
				if(!$sender->hasPermission("tcoinsystem.admin")) {
					$sender->sendMessage("§cYou don't have permission to use this command!");
					return false;
				}
				$this->openAdminPanel($sender);
				return true;
		}
		return false;
	}

	private function openMainForm(Player $player) {
		$form = new SimpleForm(function(Player $player, $data) {
			if($data === null) return;

			switch($data) {
				case 0:
					$this->openBuyForm($player);
					break;
				case 1:
					$this->openShopForm($player);
					break;
				case 2:
					$this->openStatusForm($player);
					break;
				case 3:
					$this->openInfoForm($player);
					break;
			}
		});

		$form->setTitle("§8T Coin System");
		$form->setContent("§7Current Price: §6$" . number_format($this->currentPrice));
		$form->addButton("§l§eBuy T Coin\n§r§8Price: §6$" . number_format($this->currentPrice), 0, "textures/items/gold_ingot");
		$form->addButton("§l§bT Shop\n§r§8Spend your T Coins", 0, "textures/blocks/chest_front");
		$form->addButton("§l§aStatus\n§r§8View your balance", 0, "textures/items/paper");
		$form->addButton("§l§9Information\n§r§8About this plugin", 0, "textures/items/book_enchanted");
		$player->sendForm($form);
	}

	private function openBuyForm(Player $player) {
		$money = $this->economyAPI->myMoney($player);
		$tcoins = $this->getTCoins($player);

		$changeText = "§7Initial Price";
		if(count($this->priceHistory) > 1) {
			$latest = end($this->priceHistory);
			$direction = $latest['change'] >= 0 ? '§a↑' : '§c↓';
			$changeText = "§7Last Change: " . $direction . abs(round($latest['change'] * 100)) . "%";
		}

		$form = new CustomForm(function(Player $player, $data) use ($money) {
			if($data === null) return;

			if(isset($data[1]) && is_numeric($data[1]) && $data[1] > 0) {
				$amount = (int)$data[1];
				$totalCost = $amount * $this->currentPrice;

				if($this->economyAPI->myMoney($player) >= $totalCost) {
					$this->economyAPI->reduceMoney($player, $totalCost);
					$this->addTCoins($player, $amount);
					$player->sendMessage("§aPurchased §e" . $amount . " §aT Coins for §6$" . number_format($totalCost));
				} else {
					$player->sendMessage("§cYou need §6$" . number_format($totalCost - $money) . " §cmore to buy " . $amount . " T Coins!");
				}
			}
		});

		$form->setTitle("§8Buy T Coin");
		$form->addLabel("§7Your Money: §6$" . number_format($money) .
			"\n§7Your T Coins: §e" . $tcoins .
			"\n\n§7Current Price: §6$" . number_format($this->currentPrice) .
			"\n" . $changeText);
		$form->addInput("§7Amount:", "Enter amount", "1");
		$player->sendForm($form);
	}

	private function openShopForm(Player $player) {
		$tcoins = $this->getTCoins($player);
		$items = $this->shopItems->get('items', []);

		$form = new SimpleForm(function(Player $player, $data) use ($items, $tcoins) {
			if($data === null) return;

			if(isset($items[$data])) {
				$item = $items[$data];
				if($tcoins >= $item['price']) {
					$this->processShopPurchase($player, $item);
				} else {
					$player->sendMessage("§cYou need §e" . ($item['price'] - $tcoins) . " §cmore T Coins!");
				}
			}
		});

		$form->setTitle("§8T Shop");
		$form->setContent("§7Your T Coins: §e" . $tcoins);

		foreach($items as $item) {
			$form->addButton("§l§b" . $item['name'] . "\n§r§e" . $item['price'] . " T Coins", 0, $item['image']);
		}

		$player->sendForm($form);
	}

	private function processShopPurchase(Player $player, array $item): void {
		$this->removeTCoins($player, $item['price']);

		switch($item['type']) {
			case 'tag':
			case 'mafia':
				$code = $this->generateUniqueCode();
				$this->recordOrder($player, $item['type'], $code);
				$player->sendMessage("§aPurchased §b" . $item['name'] . " §afor §e" . $item['price'] . " T Coins");
				$player->sendMessage("§aYour code: §e" . $code);
				$player->sendMessage("§7This code has been saved to our records.");
				break;

			case 'op_sword':
				$sword = VanillaItems::DIAMOND_SWORD();
				$sword->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), 5));
				$sword->addEnchantment(new EnchantmentInstance(VanillaEnchantments::EFFICIENCY(), 5));
				$sword->addEnchantment(new EnchantmentInstance(VanillaEnchantments::MENDING(), 1));
				$sword->addEnchantment(new EnchantmentInstance(VanillaEnchantments::UNBREAKING(), 3));
				$sword->setCustomName("§6OP Sword");
				$player->getInventory()->addItem($sword);
				$player->sendMessage("§aPurchased §b" . $item['name'] . " §afor §e" . $item['price'] . " T Coins");
				break;
		}
	}

	private function openStatusForm(Player $player) {
		$tcoins = $this->getTCoins($player);
		$worth = $tcoins * $this->currentPrice;

		$form = new SimpleForm(function(Player $player, $data) {
			if($data === 0) $this->openMainForm($player);
		});

		$form->setTitle("§8T Coin Status");
		$form->setContent("§7Your T Coins: §e" . $tcoins .
			"\n§7Current Worth: §6$" . number_format($worth) .
			"\n\n§7Current Price: §6$" . number_format($this->currentPrice) .
			"\n§7Minimum Price: §6$90,000");
		$form->addButton("§aBack");
		$player->sendForm($form);
	}

	private function openInfoForm(Player $player) {
		$form = new SimpleForm(function(Player $player, $data) {
			if($data === 0) $this->openMainForm($player);
		});

		$form->setTitle("§8Plugin Information");
		$form->setContent("§7TCoin System v1.1.0\n\n" .
			"§7Dynamic Pricing:\n" .
			"§8- §7Changes every hour (±10%)\n" .
			"§8- §7Never below §6$90,000\n\n" .
			"§7Created by: §bTheWindows");
		$form->addButton("§aBack");
		$player->sendForm($form);
	}

	private function openAdminPanel(Player $player) {
		$form = new SimpleForm(function(Player $player, $data) {
			if($data === null) return;

			switch($data) {
				case 0:
					$this->openPriceControl($player);
					break;
				case 1:
					$this->openShopEditor($player);
					break;
				case 2:
					$this->openPlayerManagement($player);
					break;
				case 3:
					$this->openOrderManagement($player);
					break;
			}
		});

		$form->setTitle("§4TCoin Admin Panel");
		$form->setContent("§7Current Price: §6$" . number_format($this->currentPrice));
		$form->addButton("§l§6Price Control", 0, "textures/items/gold_ingot");
		$form->addButton("§l§bShop Editor", 0, "textures/blocks/chest_front");
		$form->addButton("§l§cPlayer Management", 0, "textures/items/book_enchanted");
		$form->addButton("§l§aOrder Management", 0, "textures/items/paper");
		$player->sendForm($form);
	}

	private function openOrderManagement(Player $player) {
		$orders = $this->orderData->getAll();

		if(empty($orders)) {
			$player->sendMessage("§cNo orders found!");
			return;
		}

		$form = new SimpleForm(function(Player $player, $data) use ($orders) {
			if($data === null) return;

			$orderIds = array_keys($orders);
			if(isset($orderIds[$data])) {
				$this->openOrderDetails($player, $orderIds[$data]);
			}
		});

		$form->setTitle("§8Order Management");
		foreach($orders as $id => $order) {
			$form->addButton("§l§b" . $order['player'] . "\n§r§8" . $order['item'] . " - " . $order['date']);
		}
		$player->sendForm($form);
	}

	private function openOrderDetails(Player $player, int $orderId) {
		$orders = $this->orderData->getAll();
		$order = $orders[$orderId] ?? null;

		if($order === null) {
			$player->sendMessage("§cOrder not found!");
			return;
		}

		$form = new SimpleForm(function(Player $player, $data) use ($orderId) {
			if($data === 0) $this->openOrderManagement($player);
			elseif($data === 1) $this->deleteOrder($player, $orderId);
		});

		$form->setTitle("§8Order #" . $orderId);
		$form->setContent(
			"§7Player: §b" . $order['player'] . "\n" .
			"§7Item: §e" . $order['item'] . "\n" .
			"§7Code: §a" . $order['code'] . "\n" .
			"§7Date: §f" . $order['date'] . "\n" .
			"§7Timestamp: §f" . $order['timestamp']
		);
		$form->addButton("§aBack");
		$form->addButton("§cDelete Order");
		$player->sendForm($form);
	}

	private function deleteOrder(Player $player, int $orderId): void {
		$orders = $this->orderData->getAll();

		if(isset($orders[$orderId])) {
			unset($orders[$orderId]);
			$this->orderData->setAll($orders);
			$this->orderData->save();
			$player->sendMessage("§aOrder #$orderId has been deleted.");
		} else {
			$player->sendMessage("§cOrder not found!");
		}

		$this->openOrderManagement($player);
	}

	private function openPriceControl(Player $player) {
		$form = new CustomForm(function(Player $player, $data) {
			if($data === null) return;

			if(isset($data[0]) && is_numeric($data[0])) {
				$newPrice = (int)$data[0];
				if($newPrice >= 90000) {
					$this->currentPrice = $newPrice;
					$this->savePriceData();
					$player->sendMessage("§aPrice set to §6$" . number_format($newPrice));


					foreach($this->getServer()->getOnlinePlayers() as $onlinePlayer) {
						$this->updateScoreHudTags($onlinePlayer);
					}
				} else {
					$player->sendMessage("§cPrice must be at least §6$90,000");
				}
			}
		});

		$form->setTitle("§8Price Control");
		$form->addInput("New Price:", "Enter amount", (string)$this->currentPrice);
		$form->addLabel("§7Minimum: §6$90,000");
		$player->sendForm($form);
	}

	private function openShopEditor(Player $player) {
		$items = $this->shopItems->get('items', []);

		$form = new SimpleForm(function(Player $player, $data) use ($items) {
			if($data === null) return;

			if($data === count($items)) {
				$this->openAddItemForm($player);
			} else {
				$this->openEditItemForm($player, $data);
			}
		});

		$form->setTitle("§8Shop Editor");
		foreach($items as $item) {
			$form->addButton("§l§b" . $item['name'] . "\n§r§e" . $item['price'] . " T Coins", 0, $item['image']);
		}
		$form->addButton("§l§aAdd New Item", 0, "textures/items/nether_star");
		$player->sendForm($form);
	}

	private function openAddItemForm(Player $player) {
		$form = new CustomForm(function(Player $player, $data) {
			if($data === null) return;

			if(isset($data[0], $data[1], $data[2])) {
				$items = $this->shopItems->get('items', []);
				$items[] = [
					'name' => $data[0],
					'price' => (int)$data[1],
					'type' => $data[2],
					'image' => $data[3] ?? 'textures/items/nether_star'
				];
				$this->shopItems->set('items', $items);
				$this->shopItems->save();
				$player->sendMessage("§aItem added to shop!");
				$this->openShopEditor($player);
			}
		});

		$form->setTitle("§8Add Shop Item");
		$form->addInput("Item Name:", "Tag Purchase");
		$form->addInput("Price (T Coins):", "50");
		$form->addDropdown("Item Type:", ["tag", "mafia", "op_sword"]);
		$form->addInput("Image Path:", "textures/items/name_tag");
		$player->sendForm($form);
	}

	private function openEditItemForm(Player $player, int $index) {
		$items = $this->shopItems->get('items', []);
		$item = $items[$index] ?? null;
		if($item === null) return;

		$form = new CustomForm(function(Player $player, $data) use ($index, $items) {
			if($data === null) return;

			if(isset($data[0], $data[1], $data[2])) {
				$items[$index] = [
					'name' => $data[0],
					'price' => (int)$data[1],
					'type' => $data[2],
					'image' => $data[3] ?? 'textures/items/nether_star'
				];
				$this->shopItems->set('items', $items);
				$this->shopItems->save();
				$player->sendMessage("§aItem updated!");
				$this->openShopEditor($player);
			}
		});

		$form->setTitle("§l§bEdit: " . $item['name']);
		$form->addInput("Name:", $item['name']);
		$form->addInput("Price:", (string)$item['price']);
		$form->addDropdown("Type:", ["tag", "mafia", "op_sword"], array_search($item['type'], ["tag", "mafia", "op_sword"]));
		$form->addInput("Image Path:", $item['image']);
		$player->sendForm($form);
	}

	private function openPlayerManagement(Player $player) {
		$players = [];
		foreach($this->tcoinData->getAll() as $name => $balance) {
			if($name !== 'admin_orders') { // Skip admin orders data
				$players[] = $name;
			}
		}

		$form = new SimpleForm(function(Player $player, $data) use ($players) {
			if($data === null) return;
			$this->openEditPlayerBalance($player, $players[$data]);
		});

		$form->setTitle("§8Player Management");
		foreach($players as $p) {
			$form->addButton("§l§b" . $p . "\n§r§e" . $this->tcoinData->get($p, 0) . " T Coins");
		}
		$player->sendForm($form);
	}

	private function openEditPlayerBalance(Player $admin, string $target) {
		$balance = $this->tcoinData->get($target, 0);

		$form = new CustomForm(function(Player $admin, $data) use ($target) {
			if($data === null) return;

			if(isset($data[0]) && is_numeric($data[0])) {
				$newBalance = (int)$data[0];
				$this->tcoinData->set($target, $newBalance);
				$this->tcoinData->save();
				$admin->sendMessage("§aUpdated §b" . $target . "'s §abalance to §e" . $newBalance . " T Coins");
				$targetPlayer = $this->getServer()->getPlayerExact($target);
				if($targetPlayer !== null) {
					$this->updateScoreHudTags($targetPlayer);
				}
			}
		});

		$form->setTitle("§8Edit: " . $target);
		$form->addInput("New Balance:", (string)$balance);
		$admin->sendForm($form);
	}

	public function getTCoins(Player $player): int {
		$name = strtolower($player->getName());
		return $this->tcoinData->get($name, 0);
	}

	public function addTCoins(Player $player, int $amount): void {
		$name = strtolower($player->getName());
		$current = $this->getTCoins($player);
		$this->tcoinData->set($name, $current + $amount);
		$this->tcoinData->save();
		$this->updateScoreHudTags($player);
	}

	public function removeTCoins(Player $player, int $amount): bool {
		$name = strtolower($player->getName());
		$current = $this->getTCoins($player);

		if($current >= $amount) {
			$this->tcoinData->set($name, $current - $amount);
			$this->tcoinData->save();
			$this->updateScoreHudTags($player);
			return true;
		}
		return false;
	}

	public function onTagResolve(TagsResolveEvent $event) {
		$player = $event->getPlayer();
		$tag = $event->getTag();

		switch($tag->getName()) {
			case "tcoins":
				$tag->setValue((string)$this->getTCoins($player));
				break;
			case "tcoin_price":
				$tag->setValue(number_format($this->currentPrice));
				break;
		}
	}

	private function updateScoreHudTags(Player $player): void {
		if(class_exists("Ifera\ScoreHud\event\TagsResolveEvent")) {
			$tags = [
				new ScoreTag("tcoins", (string)$this->getTCoins($player)),
				new ScoreTag("tcoin_price", number_format($this->currentPrice))
			];
			$ev = new TagsResolveEvent($player, $tags);
			$ev->call();
		}
	}
}